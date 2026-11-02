<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Cron;

use Orangecat\IndexerBoost\Model\Config;
use Orangecat\IndexerBoost\Model\Monitor\Clock;
use Orangecat\IndexerBoost\Model\ResourceModel\RunLog;
use Psr\Log\LoggerInterface;

/**
 * Daily removal of passive run log rows older than the configured retention
 */
class CleanupRunLog
{
    /**
     * @param RunLog $runLog
     * @param Config $config
     * @param Clock $clock
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RunLog $runLog,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Delete old runs; failures are logged, the next daily run retries
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $retentionDays = $this->config->getRetentionDays();
            $deleted = $this->runLog->deleteOlderThan(
                $this->clock->now()->modify(sprintf('-%d days', $retentionDays))
            );
            if ($deleted > 0) {
                $this->logger->info(
                    sprintf('Indexer Boost: deleted %d run log rows older than %d days', $deleted, $retentionDays)
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Indexer Boost: run log cleanup failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
