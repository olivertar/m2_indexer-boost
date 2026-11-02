<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Plugin\Indexer;

use Magento\Catalog\Model\ResourceModel\Product\Indexer\Price\Query\BaseFinalPrice;
use Magento\Framework\DB\Select;
use Orangecat\IndexerBoost\Model\Indexer\JoinOrder;
use Orangecat\IndexerBoost\Model\Indexer\Price\FullReindexScope;
use Orangecat\IndexerBoost\Plugin\Indexer\PriceQueryPlugin;
use PHPUnit\Framework\TestCase;

class PriceQueryPluginTest extends TestCase
{
    public function testInsideTheFullReindexReadsCustomerGroupsAfterTheProductValues(): void
    {
        $select = $this->createStub(Select::class);
        $joinOrder = $this->createMock(JoinOrder::class);
        $joinOrder->expects($this->once())->method('after')->with($select, 'cg')->willReturn(true);
        $scope = new FullReindexScope();

        $result = $scope->run(fn (): Select => (new PriceQueryPlugin($scope, $joinOrder))->afterGetQuery(
            $this->createStub(BaseFinalPrice::class),
            $select,
            [],
            'simple',
            [7, 3]
        ));

        $this->assertSame($select, $result);
    }

    public function testPartialReindexesKeepTheCoreSelect(): void
    {
        $joinOrder = $this->createMock(JoinOrder::class);
        $joinOrder->expects($this->never())->method('after');

        (new PriceQueryPlugin(new FullReindexScope(), $joinOrder))->afterGetQuery(
            $this->createStub(BaseFinalPrice::class),
            $this->createStub(Select::class),
            [],
            'simple',
            [7, 3]
        );
    }
}
