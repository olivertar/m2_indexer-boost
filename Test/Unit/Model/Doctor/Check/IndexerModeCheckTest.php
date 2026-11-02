<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Magento\Framework\Indexer\ConfigInterface;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSize;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSizeProvider;
use Orangecat\IndexerBoost\Model\Doctor\Check\IndexerModeCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class IndexerModeCheckTest extends TestCase
{
    use WriteGuardAdapterTrait;

    public function testAllIndexersOnScheduleIsOk(): void
    {
        $check = $this->check(
            ['catalog_product_price' => 'enabled', 'catalogrule_rule' => 'enabled'],
            10000
        );

        $results = $check->check(new DoctorContext());

        $this->assertSame('INDEXER_MODE', $check->getCode());
        $this->assertCount(1, $results);
        $this->assertSame('ok', $results[0]->toArray()['severity']);
        $this->assertSame('All indexers are in "Update by Schedule" mode', $results[0]->toArray()['message']);
        $this->assertNoWriteAttempts();
    }

    public function testRealtimeIndexersOnLargeCatalogAreWarnWithFix(): void
    {
        $check = $this->check(
            ['catalog_product_price' => 'disabled', 'catalogrule_rule' => 'enabled'],
            5000
        );

        $results = $check->check(new DoctorContext());

        $this->assertCount(1, $results);
        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'INDEXER_MODE',
                'message' => '1 indexer(s) in "Update on Save" mode: catalog_product_price',
                'recommendation' => 'Every product save reindexes synchronously, which slows down the admin, '
                    . 'imports and API calls. Switch to "Update by Schedule" so changes are processed by cron.',
                'fix' => 'bin/magento indexer:set-mode schedule catalog_product_price',
                'metrics' => ['realtime_indexers' => ['catalog_product_price'], 'products' => 5000],
            ],
            $results[0]->toArray()
        );
    }

    public function testRealtimeIndexersExplainWhatAnImportDoesToEachOfThem(): void
    {
        $check = $this->check(
            [
                'catalog_product_price' => 'disabled',
                'catalogrule_rule' => 'disabled',
                'catalogsearch_fulltext' => 'disabled',
                'customer_grid' => 'enabled',
            ],
            10000,
            [],
            [
                'catalog_product_price' => ['view_id' => 'catalog_product_price'],
                'catalogrule_rule' => ['view_id' => 'catalogrule_rule'],
                'catalogsearch_fulltext' => ['view_id' => 'catalogsearch_fulltext'],
                'customer_grid' => ['view_id' => 'customer_grid'],
            ],
            ['catalog_product_price', 'cataloginventory_stock'],
            ['catalogrule_rule', 'customer_grid']
        );

        $result = $check->check(new DoctorContext())[0]->toArray();

        $this->assertStringEndsWith(
            ' Imports make it worse: they reindex catalog_product_price synchronously for every batch of rows, '
            . 'invalidate catalogrule_rule so cron then runs their full reindexes one after another, and leave '
            . 'catalogsearch_fulltext stale until their next full reindex.',
            $result['recommendation']
        );
        $this->assertSame(
            [
                'reindexed_per_batch' => ['catalog_product_price'],
                'invalidated' => ['catalogrule_rule'],
                'not_refreshed' => ['catalogsearch_fulltext'],
            ],
            $result['metrics']['import']
        );
    }

    public function testRealtimeIndexersOnSmallCatalogAreOnlyInfo(): void
    {
        $check = $this->check(['catalog_product_price' => 'disabled', 'catalogrule_rule' => 'enabled'], 4999);

        $results = $check->check(new DoctorContext());

        $this->assertSame('info', $results[0]->toArray()['severity']);
    }

    public function testExcludedIndexersAndIndexersWithoutViewAreIgnored(): void
    {
        $check = $this->check(
            ['catalog_product_price' => 'disabled'],
            10000,
            ['catalog_product_price'],
            [
                'catalog_product_price' => [
                    'indexer_id' => 'catalog_product_price',
                    'view_id' => 'catalog_product_price',
                ],
                'no_view_indexer' => ['indexer_id' => 'no_view_indexer', 'view_id' => null],
            ]
        );

        $results = $check->check(new DoctorContext());

        $this->assertSame('ok', $results[0]->toArray()['severity']);
    }

    /**
     * @param array<string,string> $viewModes view_id => mview_state.mode
     * @param int $products
     * @param string[] $excluded
     * @param array<string,array<string,mixed>>|null $indexers
     * @param string[] $importReindexed
     * @param string[] $importInvalidated
     * @return IndexerModeCheck
     */
    private function check(
        array $viewModes,
        int $products,
        array $excluded = [],
        ?array $indexers = null,
        array $importReindexed = [],
        array $importInvalidated = []
    ): IndexerModeCheck {
        $config = $this->createStub(ConfigInterface::class);
        $config->method('getIndexers')->willReturn($indexers ?? [
            'catalog_product_price' => ['indexer_id' => 'catalog_product_price', 'view_id' => 'catalog_product_price'],
            'catalogrule_rule' => ['indexer_id' => 'catalogrule_rule', 'view_id' => 'catalogrule_rule'],
        ]);
        $sizeProvider = $this->createStub(CatalogSizeProvider::class);
        $sizeProvider->method('get')->willReturn(new CatalogSize(['simple' => $products], 0, 1, 1, 4, 0, 0));
        $adapter = $this->createWriteGuardAdapter(static fn (): array => $viewModes);

        return new IndexerModeCheck(
            $config,
            new ReadOnlyConnection($this->createResourceConnection($adapter)),
            $sizeProvider,
            5000,
            $excluded,
            $importReindexed,
            $importInvalidated
        );
    }
}
