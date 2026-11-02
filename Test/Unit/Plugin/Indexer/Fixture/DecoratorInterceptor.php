<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Plugin\Indexer\Fixture;

use Magento\Framework\Indexer\Config\DependencyInfoProviderInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Interception\Interceptor;
use Magento\Framework\Interception\InterceptorInterface;
use Magento\Framework\Interception\PluginListInterface;
use Magento\Indexer\Model\Indexer\DeferredCacheCleaner;
use Magento\Indexer\Model\Indexer\DependencyDecorator;

/**
 * Same shape as the generated DependencyDecorator\Interceptor, with an injected plugin list
 */
class DecoratorInterceptor extends DependencyDecorator implements InterceptorInterface
{
    use Interceptor;

    /**
     * @param IndexerInterface $indexer
     * @param PluginListInterface $pluginList
     * @param DependencyInfoProviderInterface $dependencyInfoProvider
     * @param IndexerRegistry $indexerRegistry
     * @param DeferredCacheCleaner $cacheCleaner
     */
    public function __construct(
        IndexerInterface $indexer,
        PluginListInterface $pluginList,
        DependencyInfoProviderInterface $dependencyInfoProvider,
        IndexerRegistry $indexerRegistry,
        DeferredCacheCleaner $cacheCleaner
    ) {
        parent::__construct($indexer, $dependencyInfoProvider, $indexerRegistry, $cacheCleaner);
        $this->pluginList = $pluginList;
        $this->subjectType = get_parent_class($this);
    }

    /**
     * Run the plugin chain like the generated interceptor (reindexAll() returns nothing)
     *
     * @return void
     */
    public function reindexAll()
    {
        $pluginInfo = $this->pluginList->getNext($this->subjectType, 'reindexAll');
        if ($pluginInfo) {
            $this->___callPlugins('reindexAll', func_get_args(), $pluginInfo);
            return;
        }
        parent::reindexAll();
    }
}
