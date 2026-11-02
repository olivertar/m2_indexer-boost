<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Plugin\Mview;

use Magento\Framework\Mview\ViewInterface;
use Orangecat\IndexerBoost\Model\Monitor\Clock;
use Orangecat\IndexerBoost\Model\Monitor\RunRecorder;
use Orangecat\IndexerBoost\Model\Monitor\ViewIdResolver;

/**
 * Records changelog (mview) updates in the passive run log.
 *
 * Calls that find nothing to do return in milliseconds and are dropped by the RunRecorder threshold.
 */
class RecordViewUpdatePlugin
{
    /**
     * @param ViewIdResolver $viewIdResolver
     * @param RunRecorder $recorder
     * @param Clock $clock
     */
    public function __construct(
        private readonly ViewIdResolver $viewIdResolver,
        private readonly RunRecorder $recorder,
        private readonly Clock $clock
    ) {
    }

    /**
     * Measure the view update and record it under its indexer id
     *
     * @param ViewInterface $subject
     * @param callable $proceed
     * @return mixed
     * @throws \Throwable the original update exception, unchanged
     */
    public function aroundUpdate(ViewInterface $subject, callable $proceed): mixed
    {
        $startedAt = $this->clock->now();
        $start = $this->clock->monotonic();

        try {
            $result = $proceed();
        } catch (\Throwable $error) {
            $this->record($subject, $startedAt, $this->clock->monotonic() - $start, $error);
            throw $error;
        }
        $this->record($subject, $startedAt, $this->clock->monotonic() - $start);

        return $result;
    }

    /**
     * Record the run under the indexer id of the view
     *
     * @param ViewInterface $view
     * @param \DateTimeImmutable $startedAt
     * @param float $durationSeconds
     * @param \Throwable|null $error
     * @return void
     */
    private function record(
        ViewInterface $view,
        \DateTimeImmutable $startedAt,
        float $durationSeconds,
        ?\Throwable $error = null
    ): void {
        $viewId = (string)$view->getId();
        try {
            $indexerId = $this->viewIdResolver->getIndexerId($viewId);
        } catch (\Throwable) {
            // Recording must never break the update: keep the run under its view id
            $indexerId = $viewId;
        }
        $this->recorder->record(
            $indexerId,
            RunRecorder::KIND_CHANGES,
            $startedAt,
            $durationSeconds,
            $error
        );
    }
}
