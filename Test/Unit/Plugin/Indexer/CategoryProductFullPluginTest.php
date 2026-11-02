<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Plugin\Indexer;

use Magento\Catalog\Model\Indexer\Category\Product\Action\Full as CoreFull;
use Orangecat\IndexerBoost\Model\Indexer\CategoryProduct\Action\Full;
use Orangecat\IndexerBoost\Model\Indexer\CategoryProduct\Action\FullFactory;
use Orangecat\IndexerBoost\Model\Indexer\Switcher;
use Orangecat\IndexerBoost\Plugin\Indexer\CategoryProductFullPlugin;
use PHPUnit\Framework\TestCase;

class CategoryProductFullPluginTest extends TestCase
{
    public function testSwitcherChoosesBetweenTheBoostFullAndTheCore(): void
    {
        $core = $this->createStub(CoreFull::class);
        $boost = $this->createMock(Full::class);
        $boost->expects($this->once())->method('execute')->willReturnSelf();
        $factory = $this->createStub(FullFactory::class);
        $factory->method('create')->willReturn($boost);
        $switcher = $this->createMock(Switcher::class);
        $switcher->expects($this->once())->method('run')->willReturnCallback(
            function (string $indexerId, callable $boostRun, callable $coreRun) use ($core): mixed {
                $this->assertSame('catalog_category_product', $indexerId);
                $this->assertSame($core, $coreRun());
                return $boostRun();
            }
        );

        $result = (new CategoryProductFullPlugin($switcher, $factory))->aroundExecute($core, fn () => $core);

        $this->assertSame($boost, $result);
    }

    public function testBoostFullIsAlsoInterceptedAndRunsItsOwnBodyWithoutSwitchingAgain(): void
    {
        $boost = $this->createStub(Full::class);
        $switcher = $this->createMock(Switcher::class);
        $switcher->expects($this->never())->method('run');
        $plugin = new CategoryProductFullPlugin($switcher, $this->createStub(FullFactory::class));

        $this->assertSame($boost, $plugin->aroundExecute($boost, fn () => $boost));
    }
}
