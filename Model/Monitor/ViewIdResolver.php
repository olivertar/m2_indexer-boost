<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Monitor;

use Magento\Framework\Indexer\ConfigInterface;

/**
 * Maps an mview view id (e.g. customer_dummy) to the id of the indexer that owns it
 */
class ViewIdResolver
{
    /**
     * view id => indexer id, built on first use
     *
     * @var array<string,string>|null
     */
    private ?array $map = null;

    /**
     * @param ConfigInterface $indexerConfig
     */
    public function __construct(
        private readonly ConfigInterface $indexerConfig
    ) {
    }

    /**
     * Indexer id of the view
     *
     * @param string $viewId
     * @return string
     */
    public function getIndexerId(string $viewId): string
    {
        if ($this->map === null) {
            $this->map = [];
            foreach ((array)$this->indexerConfig->getIndexers() as $indexerId => $config) {
                $configViewId = $config['view_id'] ?? null;
                // First indexer wins, like the core ViewUpdatePlugin
                if (is_string($configViewId) && !isset($this->map[$configViewId])) {
                    $this->map[$configViewId] = (string)$indexerId;
                }
            }
        }
        return $this->map[$viewId] ?? $viewId;
    }
}
