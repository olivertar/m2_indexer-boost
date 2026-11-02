<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Plugin\Indexer;

use Magento\Indexer\Model\Indexer;
use Magento\Indexer\Model\WorkingStateProvider;
use Orangecat\IndexerBoost\Model\Monitor\Clock;
use Orangecat\IndexerBoost\Model\Monitor\RunRecorder;

/**
 * Records every full reindex in the passive run log.
 *
 * Declared on the concrete Indexer, not on IndexerInterface: the DependencyDecorator preference delegates to the
 * concrete class, so a plugin on the interface would record each run twice.
 */
class RecordFullReindexPlugin
{
    /**
     * @param WorkingStateProvider $workingStateProvider
     * @param RunRecorder $recorder
     * @param Clock $clock
     */
    public function __construct(
        private readonly WorkingStateProvider $workingStateProvider,
        private readonly RunRecorder $recorder,
        private readonly Clock $clock
    ) {
    }

    /**
     * Measure the full reindex and record it, also when it fails
     *
     * @param Indexer $subject
     * @param callable $proceed
     * @return mixed
     * @throws \Throwable the original reindex exception, unchanged
     */
    public function aroundReindexAll(Indexer $subject, callable $proceed): mixed
    {
        $indexerId = (string)$subject->getId();
        if (!$this->willRun($indexerId)) {
            return $proceed();
        }
        $startedAt = $this->clock->now();
        $start = $this->clock->monotonic();

        try {
            $result = $proceed();
        } catch (\Throwable $error) {
            $this->recorder->record(
                $indexerId,
                RunRecorder::KIND_FULL,
                $startedAt,
                $this->clock->monotonic() - $start,
                $error
            );
            throw $error;
        }
        $this->recorder->record($indexerId, RunRecorder::KIND_FULL, $startedAt, $this->clock->monotonic() - $start);

        return $result;
    }

    /**
     * Whether the core will really reindex
     *
     * Same test as Indexer::reindexAll(): a working indexer is skipped, so there is no run to record. When the state
     * cannot be read the run is not recorded either, and the reindex itself decides what to do.
     *
     * @param string $indexerId
     * @return bool
     */
    private function willRun(string $indexerId): bool
    {
        try {
            return !$this->workingStateProvider->isWorking($indexerId);
        } catch (\Throwable) {
            return false;
        }
    }
}
