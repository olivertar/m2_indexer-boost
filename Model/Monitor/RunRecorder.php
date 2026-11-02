<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Monitor;

use Orangecat\IndexerBoost\Model\Config;
use Orangecat\IndexerBoost\Model\ResourceModel\RunLog;
use Psr\Log\LoggerInterface;

/**
 * Records real indexer runs in the passive run log.
 *
 * Called from reindex plugins: it must never interfere with the reindex, so every failure is logged and dropped.
 */
class RunRecorder
{
    public const KIND_FULL = 'full';
    public const KIND_CHANGES = 'changes';

    /**
     * Maximum stored length of an error
     */
    private const MAX_ERROR_LENGTH = 2000;

    /**
     * @param RunLog $runLog
     * @param Config $config
     * @param LoggerInterface $logger
     * @param float $minChangesSeconds successful changelog runs shorter than this are not stored
     */
    public function __construct(
        private readonly RunLog $runLog,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly float $minChangesSeconds = 1.0
    ) {
    }

    /**
     * Record one run; never throws
     *
     * @param string $indexerId
     * @param string $kind self::KIND_FULL or self::KIND_CHANGES
     * @param \DateTimeImmutable $startedAt
     * @param float $durationSeconds
     * @param \Throwable|null $error exception that ended the run, null on success
     * @return void
     */
    public function record(
        string $indexerId,
        string $kind,
        \DateTimeImmutable $startedAt,
        float $durationSeconds,
        ?\Throwable $error = null
    ): void {
        try {
            if (!$this->config->isMonitorEnabled()) {
                return;
            }
            if ($kind === self::KIND_CHANGES && $error === null && $durationSeconds < $this->minChangesSeconds) {
                return;
            }
            $this->runLog->saveRun(
                $indexerId,
                $kind,
                $startedAt,
                $durationSeconds,
                $error === null
                    ? null
                    : mb_substr(get_class($error) . ': ' . $error->getMessage(), 0, self::MAX_ERROR_LENGTH)
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Indexer Boost: could not record the indexer run: ' . $e->getMessage(),
                ['indexer_id' => $indexerId, 'kind' => $kind, 'exception' => $e]
            );
        }
    }
}
