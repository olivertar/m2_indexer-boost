<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Monitor;

use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\ResourceModel\RunLog;

/**
 * Read-only summary of the passive run log, for REINDEX_REGRESSION and the admin page
 */
class RunStatsReader
{
    /**
     * @param ReadOnlyConnection $connection
     * @param Median $median
     * @param int $previousRuns successful full reindexes before the last one that form the usual duration
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection,
        private readonly Median $median,
        private readonly int $previousRuns = 5
    ) {
    }

    /**
     * Stats per indexer, sorted by indexer id
     *
     * @return array<string,IndexerRunStats>
     */
    public function getStats(): array
    {
        $table = $this->connection->getTableName(RunLog::TABLE);
        $counts = [];
        foreach ($this->connection->fetchAll(sprintf(
            'SELECT indexer_id, kind, COUNT(*) AS runs, SUM(failed) AS failures FROM %s GROUP BY indexer_id, kind',
            $table
        )) as $row) {
            $counts[(string)$row['indexer_id']][(string)$row['kind']] = [(int)$row['runs'], (int)$row['failures']];
        }

        $recent = [];
        foreach ($this->connection->fetchAll(
            sprintf(
                'SELECT indexer_id, started_at, duration_s FROM ('
                . 'SELECT indexer_id, started_at, duration_s, ROW_NUMBER() OVER (PARTITION BY indexer_id '
                . "ORDER BY started_at DESC, run_id DESC) AS position FROM %s WHERE kind = 'full' AND failed = 0"
                . ') ranked WHERE position <= ? ORDER BY indexer_id, position',
                $table
            ),
            [$this->previousRuns + 1]
        ) as $row) {
            $recent[(string)$row['indexer_id']][] = $row;
        }

        $stats = [];
        foreach ($counts as $indexerId => $kinds) {
            $runs = $recent[$indexerId] ?? [];
            $last = array_shift($runs);
            $previous = array_map(static fn (array $run): float => (float)$run['duration_s'], $runs);
            $stats[$indexerId] = new IndexerRunStats(
                $indexerId,
                $kinds[RunRecorder::KIND_FULL][0] ?? 0,
                $kinds[RunRecorder::KIND_FULL][1] ?? 0,
                $kinds[RunRecorder::KIND_CHANGES][0] ?? 0,
                $kinds[RunRecorder::KIND_CHANGES][1] ?? 0,
                $last === null ? null : (string)$last['started_at'],
                $last === null ? null : (float)$last['duration_s'],
                count($previous),
                $this->median->calculate($previous)
            );
        }
        ksort($stats, SORT_STRING);
        return $stats;
    }
}
