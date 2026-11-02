<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSize;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSizeProvider;
use Orangecat\IndexerBoost\Model\Doctor\Check\CatalogSizeCheck;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use PHPUnit\Framework\TestCase;

class CatalogSizeCheckTest extends TestCase
{
    public function testReportsCatalogSizeAsInfo(): void
    {
        $size = new CatalogSize(['simple' => 1800, 'configurable' => 240], 35, 3, 2, 4, 5, 1);
        $provider = $this->createStub(CatalogSizeProvider::class);
        $provider->method('get')->willReturn($size);
        $check = new CatalogSizeCheck($provider);

        $results = $check->check(new DoctorContext());

        $this->assertSame('CATALOG_SIZE', $check->getCode());
        $this->assertFalse($check->isHeavy());
        $this->assertCount(1, $results);
        $this->assertSame(
            [
                'severity' => 'info',
                'code' => 'CATALOG_SIZE',
                'message' => '2040 products (simple: 1800, configurable: 240), 35 categories, 3 store views, '
                    . '2 websites, 4 customer groups, 1 of 5 catalog rules active',
                'recommendation' => 'Context for the other checks and for benchmark comparisons.',
                'fix' => '',
                'metrics' => $size->toArray(),
            ],
            $results[0]->toArray()
        );
    }
}
