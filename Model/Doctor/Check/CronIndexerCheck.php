<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Last successful run of the indexer cron jobs in cron_schedule
 */
class CronIndexerCheck implements CheckInterface
{
    public const CODE = 'CRON_INDEXER';

    /**
     * @param ReadOnlyConnection $connection
     * @param int $maxMinutesSinceSuccess
     * @param string[] $jobs
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection,
        private readonly int $maxMinutesSinceSuccess = 15,
        private readonly array $jobs = ['indexer_update_all_views', 'indexer_reindex_all_invalid']
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
        // Magento runs its connection in UTC, so UTC_TIMESTAMP() is comparable with cron_schedule timestamps
        $lastSuccess = $this->connection->fetchPairs(
            sprintf(
                "SELECT job_code, TIMESTAMPDIFF(MINUTE, MAX(finished_at), UTC_TIMESTAMP()) FROM %s "
                . "WHERE status = 'success' AND job_code IN (%s) GROUP BY job_code",
                $this->connection->getTableName('cron_schedule'),
                implode(', ', array_fill(0, count($this->jobs), '?'))
            ),
            array_values($this->jobs)
        );

        $minutes = [];
        $problems = [];
        foreach ($this->jobs as $job) {
            $minutes[$job] = isset($lastSuccess[$job]) ? (int)$lastSuccess[$job] : null;
            if ($minutes[$job] === null) {
                $problems[] = $job . ' never succeeded';
            } elseif ($minutes[$job] > $this->maxMinutesSinceSuccess) {
                $problems[] = sprintf('%s last succeeded %d min ago', $job, $minutes[$job]);
            }
        }

        if (!$problems) {
            return [
                new CheckResult(
                    Severity::Ok,
                    self::CODE,
                    sprintf(
                        '%s succeeded in the last %d minutes',
                        implode(', ', $this->jobs),
                        $this->maxMinutesSinceSuccess
                    ),
                    '',
                    '',
                    ['minutes_since_success' => $minutes]
                ),
            ];
        }

        return [
            new CheckResult(
                Severity::Critical,
                self::CODE,
                'Indexer cron jobs are not running: ' . implode(', ', $problems),
                'Without these jobs scheduled indexers never process their changelogs and invalid indexers are '
                . 'never rebuilt, so the storefront keeps stale data.',
                'bin/magento cron:install (verify with crontab -l and bin/magento cron:run --group=index)',
                ['minutes_since_success' => $minutes]
            ),
        ];
    }
}
