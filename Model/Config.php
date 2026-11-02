<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Module configuration
 */
class Config
{
    public const XML_PATH_MONITOR_ENABLED = 'indexer_boost/monitor/enabled';
    public const XML_PATH_RETENTION_DAYS = 'indexer_boost/monitor/retention_days';

    /**
     * Flags of a rewritten indexer: indexer_boost/<indexer_id>/<flag> (SPEC 6.1)
     */
    private const XML_PATH_INDEXER = 'indexer_boost/%s/%s';

    /**
     * Retention used when the configured value is not a positive number
     */
    public const DEFAULT_RETENTION_DAYS = 90;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether real reindex runs are recorded in the passive run log
     *
     * @return bool
     */
    public function isMonitorEnabled(): bool
    {
        return (bool)$this->scopeConfig->getValue(self::XML_PATH_MONITOR_ENABLED);
    }

    /**
     * Days the passive run log keeps
     *
     * @return int
     */
    public function getRetentionDays(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_RETENTION_DAYS);
        $days = is_numeric($value) ? (int)$value : 0;
        return $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * Whether the Indexer Boost implementation of a rewritten indexer replaces the core one
     *
     * Read on every reindex, so it can be switched without recompiling DI.
     *
     * @param string $indexerId
     * @return bool
     */
    public function isIndexerEnabled(string $indexerId): bool
    {
        return (bool)$this->scopeConfig->getValue(sprintf(self::XML_PATH_INDEXER, $indexerId, 'enabled'));
    }

    /**
     * Whether a failure while preparing the Indexer Boost implementation runs the core one instead; on by default
     *
     * @param string $indexerId
     * @return bool
     */
    public function isFallbackOnError(string $indexerId): bool
    {
        $value = $this->scopeConfig->getValue(sprintf(self::XML_PATH_INDEXER, $indexerId, 'fallback_on_error'));
        return $value === null || (bool)$value;
    }

    /**
     * Entities per batch of the Indexer Boost implementation, null to keep its default
     *
     * @param string $indexerId
     * @return int|null
     */
    public function getBatchSize(string $indexerId): ?int
    {
        $value = $this->scopeConfig->getValue(sprintf(self::XML_PATH_INDEXER, $indexerId, 'batch_size'));
        return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
    }
}
