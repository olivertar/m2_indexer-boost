<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Orangecat\IndexerBoost\Model\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    #[DataProvider('enabledProvider')]
    public function testMonitorEnabledFlag(?string $value, bool $expected): void
    {
        $this->assertSame($expected, $this->config(['indexer_boost/monitor/enabled' => $value])->isMonitorEnabled());
    }

    /**
     * @return array<string,array{?string,bool}>
     */
    public static function enabledProvider(): array
    {
        return ['enabled' => ['1', true], 'disabled' => ['0', false], 'missing' => [null, false]];
    }

    #[DataProvider('retentionProvider')]
    public function testRetentionDaysFallsBackToNinetyWhenInvalid(?string $value, int $expected): void
    {
        $this->assertSame(
            $expected,
            $this->config(['indexer_boost/monitor/retention_days' => $value])->getRetentionDays()
        );
    }

    /**
     * @return array<string,array{?string,int}>
     */
    public static function retentionProvider(): array
    {
        return [
            'configured' => ['30', 30],
            'zero' => ['0', 90],
            'negative' => ['-5', 90],
            'not a number' => ['abc', 90],
            'missing' => [null, 90],
        ];
    }

    #[DataProvider('enabledProvider')]
    public function testRewrittenIndexerIsEnabledPerIndexer(?string $value, bool $expected): void
    {
        $config = $this->config(['indexer_boost/catalog_category_product/enabled' => $value]);

        $this->assertSame($expected, $config->isIndexerEnabled('catalog_category_product'));
        $this->assertFalse($config->isIndexerEnabled('catalog_product_price'));
    }

    #[DataProvider('fallbackProvider')]
    public function testFallbackToTheCoreIsOnUnlessTurnedOff(?string $value, bool $expected): void
    {
        $config = $this->config(['indexer_boost/catalog_category_product/fallback_on_error' => $value]);

        $this->assertSame($expected, $config->isFallbackOnError('catalog_category_product'));
    }

    /**
     * @return array<string,array{?string,bool}>
     */
    public static function fallbackProvider(): array
    {
        return ['on' => ['1', true], 'off' => ['0', false], 'missing' => [null, true]];
    }

    #[DataProvider('batchSizeProvider')]
    public function testBatchSizeIsOptional(?string $value, ?int $expected): void
    {
        $config = $this->config(['indexer_boost/catalog_category_product/batch_size' => $value]);

        $this->assertSame($expected, $config->getBatchSize('catalog_category_product'));
    }

    /**
     * @return array<string,array{?string,int|null}>
     */
    public static function batchSizeProvider(): array
    {
        return [
            'configured' => ['5000', 5000],
            'empty' => ['', null],
            'zero' => ['0', null],
            'not a number' => ['many', null],
            'missing' => [null, null],
        ];
    }

    /**
     * @param array<string,string|null> $values
     * @return Config
     */
    private function config(array $values): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(static fn (string $path) => $values[$path] ?? null);
        return new Config($scopeConfig);
    }
}
