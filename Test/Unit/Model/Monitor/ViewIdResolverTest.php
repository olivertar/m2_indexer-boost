<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Monitor;

use Magento\Framework\Indexer\ConfigInterface;
use Orangecat\IndexerBoost\Model\Monitor\ViewIdResolver;
use PHPUnit\Framework\TestCase;

class ViewIdResolverTest extends TestCase
{
    private const INDEXERS = [
        'customer_grid' => ['indexer_id' => 'customer_grid', 'view_id' => 'customer_dummy'],
        'catalog_product_price' => ['indexer_id' => 'catalog_product_price', 'view_id' => 'catalog_product_price'],
        'design_config_grid' => ['indexer_id' => 'design_config_grid', 'view_id' => 'design_config_dummy'],
    ];

    public function testMapsViewIdToIndexerId(): void
    {
        $this->assertSame('customer_grid', $this->resolver()->getIndexerId('customer_dummy'));
    }

    public function testViewWithoutIndexerKeepsItsViewId(): void
    {
        $this->assertSame('third_party_view', $this->resolver()->getIndexerId('third_party_view'));
    }

    public function testIndexerConfigIsReadOnce(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->expects($this->once())->method('getIndexers')->willReturn(self::INDEXERS);
        $resolver = new ViewIdResolver($config);

        $this->assertSame(
            ['customer_grid', 'design_config_grid', 'catalog_product_price'],
            [
                $resolver->getIndexerId('customer_dummy'),
                $resolver->getIndexerId('design_config_dummy'),
                $resolver->getIndexerId('catalog_product_price'),
            ]
        );
    }

    /**
     * @return ViewIdResolver
     */
    private function resolver(): ViewIdResolver
    {
        $config = $this->createStub(ConfigInterface::class);
        $config->method('getIndexers')->willReturn(self::INDEXERS);

        return new ViewIdResolver($config);
    }
}
