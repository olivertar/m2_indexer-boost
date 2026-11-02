<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Catalog;

use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSizeProvider;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class CatalogSizeProviderTest extends TestCase
{
    use WriteGuardAdapterTrait;

    public function testCountsCatalogEntities(): void
    {
        $adapter = $this->createWriteGuardAdapter(static function (string $sql): mixed {
            return match (true) {
                str_contains($sql, 'pfx_catalog_product_entity') => ['simple' => '1800', 'configurable' => '240'],
                str_contains($sql, 'pfx_catalog_category_entity') => '35',
                str_contains($sql, 'pfx_store_website') => '2',
                str_contains($sql, 'pfx_store') => '3',
                str_contains($sql, 'pfx_customer_group') => '4',
                str_contains($sql, 'pfx_catalogrule') => ['total' => '5', 'active' => '1'],
                default => throw new \LogicException('Unexpected query: ' . $sql),
            };
        });
        $provider = new CatalogSizeProvider(new ReadOnlyConnection($this->createResourceConnection($adapter)));

        $size = $provider->get(new DoctorContext());

        $this->assertSame(
            [
                'products' => 2040,
                'products_by_type' => ['simple' => 1800, 'configurable' => 240],
                'categories' => 35,
                'stores' => 3,
                'websites' => 2,
                'customer_groups' => 4,
                'catalog_rules' => 5,
                'active_catalog_rules' => 1,
            ],
            $size->toArray()
        );
        $this->assertNoWriteAttempts();
    }

    public function testQueriesRunOncePerDoctorRun(): void
    {
        $adapter = $this->createWriteGuardAdapter(
            static fn (string $sql, string $method): mixed => $method === 'fetchOne' ? '1' : []
        );
        $provider = new CatalogSizeProvider(new ReadOnlyConnection($this->createResourceConnection($adapter)));
        $context = new DoctorContext();

        $first = $provider->get($context);
        $queries = count($this->writeGuardQueries);
        $second = $provider->get($context);

        $this->assertSame($first, $second);
        $this->assertCount($queries, $this->writeGuardQueries);
    }
}
