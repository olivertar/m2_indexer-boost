<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Indexer;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Orangecat\IndexerBoost\Model\Indexer\RewrittenIndexers;
use Orangecat\IndexerBoost\Model\Indexer\Toggle;
use PHPUnit\Framework\TestCase;

class ToggleTest extends TestCase
{
    public function testSavesTheFlagOfARewrittenIndexerAndCleansTheConfigCache(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->exactly(2))->method('save')->willReturnCallback(
            function (string $path, string $value): void {
                static $calls = [];
                $calls[] = [$path, $value];
                $this->assertContains($calls[count($calls) - 1], [
                    ['indexer_boost/catalog_category_product/enabled', '1'],
                    ['indexer_boost/catalog_category_product/enabled', '0'],
                ]);
            }
        );
        $cacheTypeList = $this->createMock(TypeListInterface::class);
        $cacheTypeList->expects($this->exactly(2))->method('cleanType')->with('config');
        $toggle = new Toggle($writer, $cacheTypeList, new RewrittenIndexers(['catalog_category_product']));

        $toggle->set('catalog_category_product', true);
        $toggle->set('catalog_category_product', false);
    }

    public function testRefusesAnIndexerWithoutRewrite(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->never())->method('save');
        $toggle = new Toggle(
            $writer,
            $this->createStub(TypeListInterface::class),
            new RewrittenIndexers(['catalog_category_product'])
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'catalog_product_price has no Indexer Boost implementation. Rewritten: catalog_category_product'
        );

        $toggle->set('catalog_product_price', true);
    }
}
