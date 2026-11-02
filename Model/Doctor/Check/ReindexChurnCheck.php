<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Config;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;
use Orangecat\IndexerBoost\Model\Monitor\RunRecorder;
use Orangecat\IndexerBoost\Model\ResourceModel\RunLog;

/**
 * Indexing that piles up: repeated full reindexes, full reindexes running at the same time and index cron jobs
 * that fall behind, over a recent window
 */
class ReindexChurnCheck implements CheckInterface
{
    public const CODE = 'REINDEX_CHURN';
    private const LISTED_OVERLAPS = 5;

    /**
     * @param ReadOnlyConnection $connection
     * @param Config $config
     * @param int $windowHours
     * @param int $maxFullRuns full reindexes of one indexer in the window from which it is reported
     * @param string[] $cronJobs index cron jobs
     * @param int $cronIntervalSeconds how often the index cron jobs are scheduled
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection,
        private readonly Config $config,
        private readonly int $windowHours = 24,
        private readonly int $maxFullRuns = 3,
        private readonly array $cronJobs = [],
        private readonly int $cronIntervalSeconds = 60
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
        $results = [];
        $monitored = $this->config->isMonitorEnabled();
        $fullRuns = $monitored ? $this->fullRuns() : [];
        if ($fullRuns) {
            $results[] = new CheckResult(
                Severity::Warn,
                self::CODE,
                sprintf(
                    'Repeated full reindexes in the last %d h: %s',
                    $this->windowHours,
                    implode(', ', array_map(
                        static fn (string $indexerId, int $runs): string => sprintf('%s (%d)', $indexerId, $runs),
                        array_keys($fullRuns),
                        $fullRuns
                    ))
                ),
                'Each full reindex rebuilds the whole index, and invalid indexers are rebuilt one after another. '
                . 'Repeated ones usually come from invalidations: imports with indexers in "Update on Save" (see '
                . 'INDEXER_MODE), changes to attributes, websites, customer groups or catalog rules, or scripts '
                . 'that run indexer:reindex.',
                'Put the indexers on "Update by Schedule" and look for indexer:reindex calls in crontabs, '
                . 'deployment and import scripts',
                ['full_runs' => $fullRuns]
            );
        }
        $overlaps = $monitored ? $this->overlaps() : [];
        if ($overlaps) {
            $listed = array_map(
                static fn (array $row): string => sprintf(
                    '%s with %s at %s UTC',
                    $row['indexer_id'],
                    $row['overlapping_indexer'],
                    $row['started_at']
                ),
                array_slice($overlaps, 0, self::LISTED_OVERLAPS)
            );
            $more = count($overlaps) - count($listed);
            $results[] = new CheckResult(
                Severity::Warn,
                self::CODE,
                sprintf(
                    'Full reindexes ran at the same time %d times in the last %d h: %s',
                    count($overlaps),
                    $this->windowHours,
                    implode(', ', $listed) . ($more > 0 ? sprintf(' and %d more', $more) : '')
                ),
                'Concurrent full reindexes compete for the same database, so each one takes longer and they can '
                . 'block each other. Usually indexer:reindex runs by hand or from a script while cron is reindexing.',
                'Leave full reindexes to cron, or pause it first (bin/magento cron:remove, then cron:install); enable '
                . 'the indexer application lock (see APPLICATION_LOCK)',
                ['overlaps' => count($overlaps)]
            );
        }
        $behind = $this->cronJobsBehind();
        if ($behind) {
            $described = [];
            foreach ($behind as $job => $stats) {
                $described[] = $job . ' ' . implode(', ', array_filter([
                    $stats['longest_s'] > $this->cronIntervalSeconds
                        ? sprintf(
                            'took up to %d s (scheduled every %d s)',
                            $stats['longest_s'],
                            $this->cronIntervalSeconds
                        )
                        : '',
                    $stats['missed'] ? $stats['missed'] . ' missed' : '',
                    $stats['failed'] ? $stats['failed'] . ' failed' : '',
                ]));
            }
            $results[] = new CheckResult(
                Severity::Warn,
                self::CODE,
                sprintf(
                    'Index cron jobs fell behind in the last %d h: %s',
                    $this->windowHours,
                    implode('; ', $described)
                ),
                'A run longer than the cron interval makes the next ones wait or be missed, so changes pile up in '
                . 'the changelogs and the indexers catch up one after another, typically after an import.',
                'Speed up the slowest indexer (see REINDEX_REGRESSION, MVIEW_BACKLOG and BATCH_SIZE) and run large '
                . 'imports off-peak; check var/log/cron.log for the failed runs',
                ['cron_jobs' => $behind]
            );
        }
        if ($results) {
            return $results;
        }

        return [
            new CheckResult(
                Severity::Ok,
                self::CODE,
                $monitored
                    ? sprintf(
                        'In the last %d h no indexer repeated its full reindex, no full reindexes overlapped and the '
                        . 'index cron jobs kept up',
                        $this->windowHours
                    )
                    : sprintf(
                        'In the last %d h the index cron jobs kept up. Repeated and overlapping full reindexes were '
                        . 'not checked: the passive reindex monitor is disabled',
                        $this->windowHours
                    )
            ),
        ];
    }

    /**
     * Full reindexes per indexer in the window, for indexers at or above the threshold
     *
     * @return array<string,int>
     */
    private function fullRuns(): array
    {
        // started_at is stored in UTC, like UTC_TIMESTAMP()
        $runs = $this->connection->fetchPairs(
            sprintf(
                'SELECT indexer_id, COUNT(*) FROM %s WHERE kind = \'%s\' '
                . 'AND started_at >= UTC_TIMESTAMP() - INTERVAL ? HOUR '
                . 'GROUP BY indexer_id HAVING COUNT(*) >= ? ORDER BY COUNT(*) DESC, indexer_id',
                $this->connection->getTableName(RunLog::TABLE),
                RunRecorder::KIND_FULL
            ),
            [$this->windowHours, $this->maxFullRuns]
        );
        $counts = [];
        foreach ($runs as $indexerId => $count) {
            $counts[(string)$indexerId] = (int)$count;
        }
        return $counts;
    }

    /**
     * Full reindexes that started while another full reindex was running, oldest first
     *
     * The started_at column has whole seconds and run_id follows the end of each run, so a run counts only when it
     * started at least one second before the other one ended: two short runs in the same second may be sequential.
     *
     * @return array<int,array<string,string>>
     */
    private function overlaps(): array
    {
        $table = $this->connection->getTableName(RunLog::TABLE);
        return $this->connection->fetchAll(
            sprintf(
                'SELECT a.indexer_id, b.indexer_id AS overlapping_indexer, b.started_at FROM %1$s a '
                . 'JOIN %1$s b ON b.kind = a.kind '
                . 'AND (b.started_at > a.started_at OR (b.started_at = a.started_at AND b.run_id > a.run_id)) '
                . 'AND b.started_at + INTERVAL 1 SECOND < a.started_at + INTERVAL a.duration_s SECOND '
                . 'WHERE a.kind = \'%2$s\' AND a.started_at >= UTC_TIMESTAMP() - INTERVAL ? HOUR '
                . 'ORDER BY b.started_at, b.run_id',
                $table,
                RunRecorder::KIND_FULL
            ),
            [$this->windowHours]
        );
    }

    /**
     * Index cron jobs with missed or failed runs, or runs longer than the cron interval, in the window
     *
     * @return array<string,array<string,int>>
     */
    private function cronJobsBehind(): array
    {
        if (!$this->cronJobs) {
            return [];
        }
        // Magento runs its connection in UTC, so UTC_TIMESTAMP() is comparable with cron_schedule timestamps
        $rows = $this->connection->fetchAll(
            sprintf(
                "SELECT job_code, SUM(status = 'missed') AS missed, SUM(status = 'error') AS errors, "
                . 'COALESCE(MAX(TIMESTAMPDIFF(SECOND, executed_at, finished_at)), 0) AS longest_s FROM %s '
                . 'WHERE job_code IN (%s) AND scheduled_at >= UTC_TIMESTAMP() - INTERVAL ? HOUR GROUP BY job_code',
                $this->connection->getTableName('cron_schedule'),
                implode(', ', array_fill(0, count($this->cronJobs), '?'))
            ),
            array_merge(array_values($this->cronJobs), [$this->windowHours])
        );

        $behind = [];
        foreach ($rows as $row) {
            $stats = [
                'missed' => (int)$row['missed'],
                'failed' => (int)$row['errors'],
                'longest_s' => (int)$row['longest_s'],
            ];
            if ($stats['missed'] || $stats['failed'] || $stats['longest_s'] > $this->cronIntervalSeconds) {
                $behind[(string)$row['job_code']] = $stats;
            }
        }
        return $behind;
    }
}
