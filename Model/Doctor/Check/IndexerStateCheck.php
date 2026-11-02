<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Magento\Framework\Indexer\ConfigInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Indexers that are invalid, suspended or working for too long.
 *
 * Reads the raw indexer_state.status: with the application lock enabled the core reports
 * "working without lock" as invalid, which would hide the real stored state.
 */
class IndexerStateCheck implements CheckInterface
{
    public const CODE = 'INDEXER_STATE';

    /**
     * @param ConfigInterface $indexerConfig
     * @param ReadOnlyConnection $connection
     * @param int $workingWarnAfterMinutes
     */
    public function __construct(
        private readonly ConfigInterface $indexerConfig,
        private readonly ReadOnlyConnection $connection,
        private readonly int $workingWarnAfterMinutes = 120
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * @inheritdoc
     */
    public function isHeavy(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function check(DoctorContext $context): array
    {
        $rows = $this->connection->fetchAll(sprintf(
            'SELECT indexer_id, status, TIMESTAMPDIFF(MINUTE, updated, UTC_TIMESTAMP()) AS minutes FROM %s',
            $this->connection->getTableName('indexer_state')
        ));

        $invalid = [];
        $suspended = [];
        $working = [];
        $configured = $this->indexerConfig->getIndexers();
        foreach ($rows as $row) {
            if (!isset($configured[$row['indexer_id']])) {
                continue;
            }
            if ($row['status'] === 'invalid') {
                $invalid[] = (string)$row['indexer_id'];
            } elseif ($row['status'] === 'suspended') {
                $suspended[] = (string)$row['indexer_id'];
            } elseif ($row['status'] === 'working' && (int)$row['minutes'] > $this->workingWarnAfterMinutes) {
                $working[(string)$row['indexer_id']] = (int)$row['minutes'];
            }
        }

        $results = [];
        if ($invalid) {
            $results[] = new CheckResult(
                Severity::Warn,
                self::CODE,
                sprintf('%d indexer(s) invalid: %s', count($invalid), implode(', ', $invalid)),
                'Invalid indexers serve stale data until rebuilt. The indexer_reindex_all_invalid cron job '
                . 'rebuilds them; if they stay invalid, check that cron runs and look for errors in '
                . 'var/log/exception.log.',
                'bin/magento indexer:reindex ' . implode(' ', $invalid),
                ['indexers' => $invalid]
            );
        }

        if ($suspended) {
            $results[] = new CheckResult(
                Severity::Warn,
                self::CODE,
                sprintf('%d indexer(s) suspended: %s', count($suspended), implode(', ', $suspended)),
                'Cron skips suspended indexers while their changelog keeps growing. This is usually left behind '
                . 'by an interrupted deployment or a manual indexer:set-status. Mark them invalid so cron '
                . 'rebuilds them.',
                'bin/magento indexer:set-status invalid ' . implode(' ', $suspended),
                ['indexers' => $suspended]
            );
        }

        if ($working) {
            $details = [];
            foreach ($working as $indexerId => $minutes) {
                $details[] = sprintf('%s (%d min)', $indexerId, $minutes);
            }
            $results[] = new CheckResult(
                Severity::Warn,
                self::CODE,
                sprintf(
                    '%d indexer(s) working for more than %d minutes: %s',
                    count($working),
                    $this->workingWarnAfterMinutes,
                    implode(', ', $details)
                ),
                'A reindex running this long may be stuck or blocked by locks. Check whether the process is '
                . 'still alive and whether the INDEXER_STUCK check flags it.',
                'bin/magento indexer-boost:doctor --only=INDEXER_STUCK',
                ['indexers' => $working]
            );
        }

        return $results ?: [new CheckResult(Severity::Ok, self::CODE, 'All indexers are valid')];
    }
}
