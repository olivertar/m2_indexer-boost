<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Indexer;

use Orangecat\IndexerBoost\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Runs the core or the Indexer Boost implementation of an indexer, read from its flag on every call (SPEC 6.1)
 */
class Switcher
{
    /**
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run one implementation
     *
     * @param string $indexerId
     * @param callable $boost Indexer Boost implementation, returns what the indexer action returns
     * @param callable $core core implementation, returns what the indexer action returns
     * @return mixed what the implementation that ran returned
     * @throws PreparationException when preparing failed and the fallback is off
     */
    public function run(string $indexerId, callable $boost, callable $core): mixed
    {
        if (!$this->config->isIndexerEnabled($indexerId)) {
            return $core();
        }
        try {
            return $boost();
        } catch (PreparationException $e) {
            if (!$this->config->isFallbackOnError($indexerId)) {
                throw $e;
            }
            $this->logger->error(
                sprintf(
                    'Indexer Boost: preparing %s failed, running the core indexer instead: %s',
                    $indexerId,
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
            return $core();
        }
    }
}
