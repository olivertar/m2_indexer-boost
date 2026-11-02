<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Plugin\Indexer;

use Magento\Catalog\Model\Indexer\Product\Eav\Action\Full as CoreFull;
use Orangecat\IndexerBoost\Model\Indexer\ProductEav\Action\Full;
use Orangecat\IndexerBoost\Model\Indexer\ProductEav\Action\FullFactory;
use Orangecat\IndexerBoost\Model\Indexer\Switcher;
use Orangecat\IndexerBoost\Plugin\Indexer\ProductEavFullPlugin;
use PHPUnit\Framework\TestCase;

class ProductEavFullPluginTest extends TestCase
{
    public function testSwitcherChoosesBetweenTheBoostFullAndTheCore(): void
    {
        $core = $this->createStub(CoreFull::class);
        $boost = $this->createMock(Full::class);
        $boost->expects($this->once())->method('execute')->with([3]);
        $factory = $this->createStub(FullFactory::class);
        $factory->method('create')->willReturn($boost);
        $coreRan = false;
        $switcher = $this->createMock(Switcher::class);
        $switcher->expects($this->once())->method('run')->willReturnCallback(
            function (string $indexerId, callable $boostRun, callable $coreRun): mixed {
                $this->assertSame('catalog_product_attribute', $indexerId);
                $coreRun();
                return $boostRun();
            }
        );
        $proceed = function (array $ids) use (&$coreRan): void {
            $this->assertSame([3], $ids);
            $coreRan = true;
        };

        (new ProductEavFullPlugin($switcher, $factory))->aroundExecute($core, $proceed, [3]);

        $this->assertTrue($coreRan);
    }

    public function testBoostFullIsAlsoInterceptedAndRunsItsOwnBodyWithoutSwitchingAgain(): void
    {
        $switcher = $this->createMock(Switcher::class);
        $switcher->expects($this->never())->method('run');
        $proceeded = false;
        $plugin = new ProductEavFullPlugin($switcher, $this->createStub(FullFactory::class));

        $plugin->aroundExecute($this->createStub(Full::class), function () use (&$proceeded): void {
            $proceeded = true;
        });

        $this->assertTrue($proceeded);
    }
}
