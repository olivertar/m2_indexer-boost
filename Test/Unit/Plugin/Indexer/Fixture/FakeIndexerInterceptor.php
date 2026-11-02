<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Plugin\Indexer\Fixture;

use Magento\Framework\Interception\Interceptor;
use Magento\Framework\Interception\InterceptorInterface;
use Magento\Framework\Interception\PluginListInterface;

/**
 * Same shape as the generated Magento\Indexer\Model\Indexer\Interceptor, with an injected plugin list
 */
class FakeIndexerInterceptor extends FakeIndexer implements InterceptorInterface
{
    use Interceptor;

    /**
     * @param string $indexerId
     * @param PluginListInterface $pluginList
     */
    public function __construct(string $indexerId, PluginListInterface $pluginList)
    {
        parent::__construct($indexerId);
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
