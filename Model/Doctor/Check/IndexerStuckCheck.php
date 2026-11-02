<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;
use Orangecat\IndexerBoost\Model\Repair\StuckIndexer;
use Orangecat\IndexerBoost\Model\Repair\StuckIndexerDetector;

/**
 * Indexers left in "working" for too long, grouped by the verdict of StuckIndexerDetector
 */
class IndexerStuckCheck implements CheckInterface
{
    public const CODE = 'INDEXER_STUCK';

    /**
     * Characters of a running statement kept in the metrics
     */
    private const MAX_STATEMENT_LENGTH = 200;

    /**
     * @param StuckIndexerDetector $detector
     */
    public function __construct(
        private readonly StuckIndexerDetector $detector
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
        $groups = [];
        $statements = [];
        foreach ($this->detector->detect() as $indexer) {
            $groups[$indexer->verdict][$indexer->indexerId] = $indexer->minutesWorking;
            if ($indexer->statement !== null) {
                $statements[$indexer->indexerId] = mb_substr($indexer->statement, 0, self::MAX_STATEMENT_LENGTH);
            }
        }
        if (!$groups) {
            return [new CheckResult(Severity::Ok, self::CODE, 'No indexer left in "working"')];
        }

        $results = [];
        foreach ($this->findings() as $verdict => [$severity, $message, $recommendation, $fix]) {
            if (!isset($groups[$verdict])) {
                continue;
            }
            $results[] = new CheckResult(
                $severity,
                self::CODE,
                $message . ': ' . $this->describe($groups[$verdict]),
                $recommendation,
                $fix,
                array_filter([
                    'minutes' => $groups[$verdict],
                    'statements' => array_intersect_key($statements, $groups[$verdict]),
                ])
            );
        }
        return $results;
    }

    /**
     * Finding per verdict: severity, message, recommendation, fix
     *
     * @return array<string,array{Severity,string,string,string}>
     */
    private function findings(): array
    {
        return [
            StuckIndexer::STUCK => [
                Severity::Critical,
                'Stuck indexers (working, lock free, no running statement on their tables)',
                'The reindex died halfway and nothing is rebuilding these indexes. Magento treats them as invalid '
                . 'only through the indexer_reindex_all_invalid cron job, which has not picked them up (see '
                . 'CRON_INDEXER). --repair marks them invalid and reindexes them, asking before each one.',
                'bin/magento indexer-boost:doctor --repair',
            ],
            StuckIndexer::DOUBTFUL => [
                Severity::Warn,
                'Probably stuck indexers (working, lock free; running statements not visible without the PROCESS '
                . 'privilege)',
                'The lock is free, but a reindex of another database user, or one that lost the lock in '
                . 'multi-process mode, cannot be ruled out. Check that no indexer:reindex or cron process is running '
                . 'on any server, or grant PROCESS to the Magento database user. --repair asks before each indexer '
                . 'and skips these with --no-interaction.',
                'bin/magento indexer-boost:doctor --repair',
            ],
            StuckIndexer::ACTIVE => [
                Severity::Info,
                'Indexers working without lock but still writing their tables (multi-process reindex)',
                'Multi-process reindexes release the lock while the workers run and take it back at the end; the '
                . 'running statements show the reindex is alive. Nothing to do.',
                '',
            ],
            StuckIndexer::RUNNING => [
                Severity::Info,
                'Long reindex in progress (lock held)',
                'A reindex holds the INDEXER lock. If it keeps running far longer than usual, see REINDEX_REGRESSION.',
                '',
            ],
            StuckIndexer::UNVERIFIABLE => [
                Severity::Info,
                'Indexers working for a long time; stuck or running cannot be told without the application lock',
                'See INDEXER_STATE and APPLICATION_LOCK. --repair never touches these indexers.',
                "app/etc/env.php: 'indexer' => ['use_application_lock' => true]",
            ],
        ];
    }

    /**
     * Describe minutes per indexer, e.g. "catalog_product_price (185 min)"
     *
     * @param array<string,int> $minutes
     * @return string
     */
    private function describe(array $minutes): string
    {
        $parts = [];
        foreach ($minutes as $indexerId => $value) {
            $parts[] = sprintf('%s (%d min)', $indexerId, $value);
        }
        return implode(', ', $parts);
    }
}
