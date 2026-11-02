<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Indexer;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

/**
 * Switches a rewritten indexer between the core and the Indexer Boost implementation (SPEC 6.1)
 */
class Toggle
{
    /**
     * @param WriterInterface $configWriter
     * @param TypeListInterface $cacheTypeList
     * @param RewrittenIndexers $rewrittenIndexers
     */
    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly RewrittenIndexers $rewrittenIndexers
    ) {
    }

    /**
     * Use the Indexer Boost implementation of the indexer (true) or the core one (false)
     *
     * @param string $indexerId
     * @param bool $enabled
     * @return void
     * @throws \InvalidArgumentException when the indexer has no Indexer Boost implementation
     */
    public function set(string $indexerId, bool $enabled): void
    {
        if (!$this->rewrittenIndexers->isRewritten($indexerId)) {
            throw new \InvalidArgumentException(sprintf(
                '%s has no Indexer Boost implementation. Rewritten: %s',
                $indexerId,
                implode(', ', $this->rewrittenIndexers->getIds()) ?: 'none yet'
            ));
        }
        $this->configWriter->save(sprintf('indexer_boost/%s/enabled', $indexerId), $enabled ? '1' : '0');
        // Read on every reindex: cleaning the config cache is enough, no DI compilation
        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
    }
}
