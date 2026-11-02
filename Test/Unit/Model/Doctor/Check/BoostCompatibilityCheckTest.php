<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Magento\Framework\Config\ScopeInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Interception\PluginListInterface;
use Magento\Framework\ObjectManager\ConfigInterface;
use Orangecat\IndexerBoost\Model\Doctor\Check\BoostCompatibilityCheck;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Indexer\RewrittenIndexers;
use PHPUnit\Framework\Constraint\IsTrue;
use PHPUnit\Framework\TestCase;

class BoostCompatibilityCheckTest extends TestCase
{
    private const PRICE_FULL = 'Magento\Catalog\Model\Indexer\Product\Price\Action\Full';
    private const EAV_FULL = 'Magento\Catalog\Model\Indexer\Product\Eav\Action\Full';

    public function testWarnsAboutPluginsOfOtherModulesAndPreferencesInEveryArea(): void
    {
        // Price: our around, then a Magento around, a third-party after; EAV: replaced by a preference
        $chains = [
            self::PRICE_FULL . '|__self' => [1 => [], 2 => 'orangecat_indexer_boost_price_full', 4 => ['vendor_after']],
            self::PRICE_FULL . '|orangecat_indexer_boost_price_full' => [2 => 'magento_around'],
        ];
        $plugins = [
            'orangecat_indexer_boost_price_full' => new RewrittenIndexers(),
            'magento_around' => new DataObject(),
            'vendor_after' => new IsTrue(),
        ];
        $pluginList = $this->createStub(PluginListInterface::class);
        $pluginList->method('getNext')->willReturnCallback(
            static fn (string $type, string $method, ?string $code = null): ?array
                => $chains[$type . '|' . ($code ?? '__self')] ?? null
        );
        $pluginList->method('getPlugin')->willReturnCallback(
            static fn (string $type, string $code): object => $plugins[$code]
        );
        $diConfig = $this->createStub(ConfigInterface::class);
        $diConfig->method('getPreference')->willReturnCallback(
            static fn (string $type): string => $type === self::EAV_FULL ? 'Vendor\Module\Model\EavFull' : $type
        );
        $areas = [];
        $scope = $this->createStub(ScopeInterface::class);
        $scope->method('getCurrentScope')->willReturn('global');
        $scope->method('setCurrentScope')->willReturnCallback(function (string $area) use (&$areas): void {
            $areas[] = $area;
        });
        $check = new BoostCompatibilityCheck(
            $pluginList,
            $diConfig,
            $scope,
            [self::PRICE_FULL => 'execute', self::EAV_FULL => 'execute'],
            ['global', 'adminhtml', 'crontab']
        );

        $results = array_map(static fn ($result): array => $result->toArray(), $check->check(new DoctorContext()));

        $this->assertSame(['global', 'adminhtml', 'crontab', 'global'], $areas);
        $this->assertSame(['warn', 'warn'], array_column($results, 'severity'));
        $this->assertSame(
            self::PRICE_FULL . '::execute() is also intercepted by vendor_after (' . IsTrue::class . ')',
            $results[0]['message']
        );
        $this->assertSame(
            self::EAV_FULL . ' is replaced by Vendor\Module\Model\EavFull (preference)',
            $results[1]['message']
        );
    }

    public function testOkWhenOnlyMagentoAndIndexerBoostInterceptTheClasses(): void
    {
        $pluginList = $this->createStub(PluginListInterface::class);
        $pluginList->method('getNext')->willReturn(null);
        $diConfig = $this->createStub(ConfigInterface::class);
        $diConfig->method('getPreference')->willReturnArgument(0);
        $check = new BoostCompatibilityCheck(
            $pluginList,
            $diConfig,
            $this->createStub(ScopeInterface::class),
            [self::PRICE_FULL => 'execute'],
            ['global']
        );

        $results = array_map(static fn ($result): array => $result->toArray(), $check->check(new DoctorContext()));

        $this->assertSame(['ok'], array_column($results, 'severity'));
    }
}
