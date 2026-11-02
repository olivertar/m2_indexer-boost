<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Config;
use Orangecat\IndexerBoost\Model\Doctor\Check\BoostStatusCheck;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Indexer\RewrittenIndexers;
use PHPUnit\Framework\TestCase;

class BoostStatusCheckTest extends TestCase
{
    public function testListsEveryRewrittenIndexerWithItsImplementation(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isIndexerEnabled')->willReturnCallback(
            static fn (string $indexerId): bool => $indexerId === 'catalog_product_price'
        );
        $check = new BoostStatusCheck(
            new RewrittenIndexers(['catalog_product_price', 'catalog_category_product']),
            $config
        );

        $results = array_map(static fn ($result): array => $result->toArray(), $check->check(new DoctorContext()));

        $this->assertCount(1, $results);
        $this->assertSame('info', $results[0]['severity']);
        $this->assertSame('BOOST_STATUS', $results[0]['code']);
        $this->assertSame(
            'Indexer Boost implementation on: catalog_product_price; core: catalog_category_product',
            $results[0]['message']
        );
        $this->assertSame(
            ['catalog_category_product' => false, 'catalog_product_price' => true],
            $results[0]['metrics']['enabled']
        );
    }
}
