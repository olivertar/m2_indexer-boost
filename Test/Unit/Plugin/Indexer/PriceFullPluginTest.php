<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Plugin\Indexer;

use Magento\Catalog\Model\Indexer\Product\Price\Action\Full as CoreFull;
use Orangecat\IndexerBoost\Model\Indexer\Price\FullReindexScope;
use Orangecat\IndexerBoost\Model\Indexer\Switcher;
use Orangecat\IndexerBoost\Plugin\Indexer\PriceFullPlugin;
use PHPUnit\Framework\TestCase;

class PriceFullPluginTest extends TestCase
{
    public function testBoostRunsTheCoreFullInsideTheScopeAndTheCoreRunsItOutside(): void
    {
        $scope = new FullReindexScope();
        $runs = [];
        $switcher = $this->createMock(Switcher::class);
        $switcher->expects($this->once())->method('run')->willReturnCallback(
            function (string $indexerId, callable $boostRun, callable $coreRun): mixed {
                $this->assertSame('catalog_product_price', $indexerId);
                $coreRun();
                return $boostRun();
            }
        );
        $proceed = function ($ids) use ($scope, &$runs): void {
            $runs[] = [$ids, $scope->isActive()];
        };

        (new PriceFullPlugin($switcher, $scope))->aroundExecute($this->createStub(CoreFull::class), $proceed, [3]);

        $this->assertSame([[[3], false], [[3], true]], $runs);
    }
}
