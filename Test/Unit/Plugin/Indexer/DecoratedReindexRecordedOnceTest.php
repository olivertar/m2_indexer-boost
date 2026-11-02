<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Plugin\Indexer;

use Magento\Framework\Indexer\Config\DependencyInfoProviderInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Indexer\Model\Indexer;
use Magento\Indexer\Model\Indexer\DeferredCacheCleaner;
use Magento\Indexer\Model\WorkingStateProvider;
use Orangecat\IndexerBoost\Model\Monitor\Clock;
use Orangecat\IndexerBoost\Model\Monitor\RunRecorder;
use Orangecat\IndexerBoost\Plugin\Indexer\RecordFullReindexPlugin;
use Orangecat\IndexerBoost\Test\Unit\Plugin\Indexer\Fixture\CountingReindexPlugin;
use Orangecat\IndexerBoost\Test\Unit\Plugin\Indexer\Fixture\DecoratorInterceptor;
use Orangecat\IndexerBoost\Test\Unit\Plugin\Indexer\Fixture\FakeIndexerInterceptor;
use Orangecat\IndexerBoost\Test\Unit\Plugin\Indexer\Fixture\InheritingPluginList;
use PHPUnit\Framework\TestCase;

/**
 * A full reindex through the DependencyDecorator preference must be recorded once.
 *
 * Both the decorator and the concrete indexer are intercepted with the core Interceptor trait, and plugins apply to
 * subtypes like in Magento, so a plugin declared on IndexerInterface would run twice.
 */
class DecoratedReindexRecordedOnceTest extends TestCase
{
    private const PLUGIN_NAME = 'orangecat_indexer_boost_record_full_reindex';

    public function testPluginDeclaredInDiXmlRecordsOneRunThroughTheDecorator(): void
    {
        $declaredType = $this->declaredType();
        $this->assertSame(Indexer::class, $declaredType);

        $recorder = $this->createMock(RunRecorder::class);
        $recorder->expects($this->once())
            ->method('record')
            ->with('catalog_product_price', RunRecorder::KIND_FULL);
        $workingState = $this->createStub(WorkingStateProvider::class);
        $workingState->method('isWorking')->willReturn(false);
        $plugin = new RecordFullReindexPlugin($workingState, $recorder, new Clock());
        $pluginList = new InheritingPluginList([$declaredType => [self::PLUGIN_NAME => $plugin]]);
        $indexer = new FakeIndexerInterceptor('catalog_product_price', $pluginList);

        $this->decorator($indexer, $pluginList)->reindexAll();

        $this->assertSame(1, $indexer->fullRuns);
    }

    public function testHarnessDetectsDoubleInterceptionOnTheInterface(): void
    {
        $plugin = new CountingReindexPlugin();
        $pluginList = new InheritingPluginList([IndexerInterface::class => ['counting' => $plugin]]);
        $indexer = new FakeIndexerInterceptor('catalog_product_price', $pluginList);

        $this->decorator($indexer, $pluginList)->reindexAll();

        $this->assertSame([2, 1], [$plugin->calls, $indexer->fullRuns]);
    }

    /**
     * Intercepted DependencyDecorator, as returned for IndexerInterface
     *
     * @param IndexerInterface $indexer
     * @param InheritingPluginList $pluginList
     * @return IndexerInterface
     */
    private function decorator(IndexerInterface $indexer, InheritingPluginList $pluginList): IndexerInterface
    {
        return new DecoratorInterceptor(
            $indexer,
            $pluginList,
            $this->createStub(DependencyInfoProviderInterface::class),
            $this->createStub(IndexerRegistry::class),
            $this->createStub(DeferredCacheCleaner::class)
        );
    }

    /**
     * Type the full reindex plugin is declared on in etc/di.xml
     *
     * @return string
     */
    private function declaredType(): string
    {
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/etc/di.xml');
        $this->assertNotFalse($xml);
        $types = $xml->xpath(sprintf('//type[plugin[@name="%s"]]', self::PLUGIN_NAME)) ?: [];
        $this->assertCount(1, $types);

        return ltrim((string)$types[0]['name'], '\\');
    }
}
