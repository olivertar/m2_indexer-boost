<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Monitor;

/**
 * Passive run log summary of one indexer
 */
class IndexerRunStats
{
    /**
     * @param string $indexerId
     * @param int $fullRuns
     * @param int $fullFailures
     * @param int $changesRuns
     * @param int $changesFailures
     * @param string|null $lastFullStartedAt UTC start of the last successful full reindex
     * @param float|null $lastFullDuration seconds of the last successful full reindex
     * @param int $previousFullRuns successful full reindexes before the last one used for the median
     * @param float|null $previousFullMedian median seconds of those runs
     */
    public function __construct(
        public readonly string $indexerId,
        public readonly int $fullRuns,
        public readonly int $fullFailures,
        public readonly int $changesRuns,
        public readonly int $changesFailures,
        public readonly ?string $lastFullStartedAt,
        public readonly ?float $lastFullDuration,
        public readonly int $previousFullRuns,
        public readonly ?float $previousFullMedian
    ) {
    }

    /**
     * Stats as an array with snake_case keys
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'indexer_id' => $this->indexerId,
            'full_runs' => $this->fullRuns,
            'full_failures' => $this->fullFailures,
            'changes_runs' => $this->changesRuns,
            'changes_failures' => $this->changesFailures,
            'last_full_started_at' => $this->lastFullStartedAt,
            'last_full_duration_s' => $this->lastFullDuration,
            'previous_full_runs' => $this->previousFullRuns,
            'previous_full_median_s' => $this->previousFullMedian,
        ];
    }
}
