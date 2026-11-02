<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Magento\Framework\App\DeploymentConfig;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSize;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSizeProvider;
use Orangecat\IndexerBoost\Model\Doctor\Check\BatchSizeCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\DbServer;
use Orangecat\IndexerBoost\Model\Doctor\Db\DbServerProvider;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use PHPUnit\Framework\TestCase;

class BatchSizeCheckTest extends TestCase
{
    public function testNoOverridesIsInfoWithExample(): void
    {
        $check = $this->check($this->deploymentConfig(null));

        $results = $check->check(new DoctorContext());

        $this->assertSame('BATCH_SIZE', $check->getCode());
        $this->assertCount(1, $results);
        $this->assertSame(
            [
                'severity' => 'info',
                'code' => 'BATCH_SIZE',
                'message' => 'No indexer/batch_size overrides in app/etc/env.php: core defaults are used '
                    . '(e.g. 5000 rows per batch for catalog_product_price, 100000 for catalog_category_product)',
                'recommendation' => 'Batch size trades memory for fewer, larger queries. Keep the defaults until '
                    . 'the benchmark shows a gain, then tune one indexer at a time.',
                'fix' => "app/etc/env.php: 'indexer' => ['batch_size' => ['catalog_product_price' => "
                    . "['default' => 5000], 'catalog_category_product' => 1000]]",
                'metrics' => ['batch_size' => []],
            ],
            $results[0]->toArray()
        );
    }

    public function testOverridesAreListedAsInfo(): void
    {
        $check = $this->check($this->deploymentConfig([
            'catalog_product_price' => ['simple' => 2000, 'default' => '3000'],
            'catalog_category_product' => 50000,
        ]));

        $result = $check->check(new DoctorContext())[0]->toArray();

        $this->assertSame('info', $result['severity']);
        $this->assertSame(
            'indexer/batch_size overrides: catalog_product_price/simple=2000, catalog_product_price/default=3000, '
            . 'catalog_category_product=50000',
            $result['message']
        );
        $this->assertSame(
            'Batch size trades memory for fewer, larger queries. Validate each override with the benchmark.',
            $result['recommendation']
        );
        $this->assertSame('', $result['fix']);
        $this->assertSame(
            ['batch_size' => [
                'catalog_product_price/simple' => 2000,
                'catalog_product_price/default' => 3000,
                'catalog_category_product' => 50000,
            ]],
            $result['metrics']
        );
    }

    public function testInvalidValuesAreWarn(): void
    {
        $check = $this->check($this->deploymentConfig([
            'catalog_product_price' => ['default' => '5k'],
            'catalog_category_product' => 0,
            'cataloginventory_stock' => ['simple' => 300],
        ]));

        $results = $check->check(new DoctorContext());

        $this->assertCount(2, $results);
        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'BATCH_SIZE',
                'message' => 'Invalid indexer/batch_size values (must be positive integers): '
                    . 'catalog_product_price/default=5k, catalog_category_product=0',
                'recommendation' => 'Invalid batch sizes make the indexer process zero or unexpected rows per '
                    . 'batch. Fix or remove them.',
                'fix' => 'Edit indexer/batch_size in app/etc/env.php',
                'metrics' => [
                    'invalid' => ['catalog_product_price/default' => '5k', 'catalog_category_product' => '0'],
                ],
            ],
            $results[0]->toArray()
        );
        $this->assertSame('info', $results[1]->toArray()['severity']);
    }

    public function testLargeCategoryProductBatchOnMariaDbIsWarn(): void
    {
        $results = $this->check($this->deploymentConfig(null), '10.11.19-MariaDB-ubu2204', 42040)
            ->check(new DoctorContext());

        $warning = array_values(array_filter(
            array_map(static fn ($result): array => $result->toArray(), $results),
            static fn (array $result): bool => $result['severity'] === 'warn'
        ));
        $this->assertCount(1, $warning);
        $this->assertSame(
            'catalog_category_product reindexes up to 100000 products per batch (42040 products): MariaDB '
            . 'can pick a nested-loop plan for that many IDs in one IN() list',
            $warning[0]['message']
        );
        $this->assertSame(
            "app/etc/env.php: 'indexer' => ['batch_size' => ['catalog_category_product' => 1000]]",
            $warning[0]['fix']
        );
        $this->assertSame(['batch' => 100000, 'products' => 42040], $warning[0]['metrics']);
    }

    public function testCategoryProductBatchWithinTheLimitIsNotWarned(): void
    {
        $results = $this->check(
            $this->deploymentConfig(['catalog_category_product' => '1000']),
            '10.11.19-MariaDB-ubu2204',
            42040
        )->check(new DoctorContext());

        $this->assertSame(['info'], array_map(static fn ($result): string => $result->toArray()['severity'], $results));
    }

    /**
     * Check over a fake server and catalog
     *
     * @param DeploymentConfig $deploymentConfig
     * @param string $serverVersion
     * @param int $products
     * @return BatchSizeCheck
     */
    private function check(
        DeploymentConfig $deploymentConfig,
        string $serverVersion = '8.0.39',
        int $products = 500
    ): BatchSizeCheck {
        $servers = $this->createStub(DbServerProvider::class);
        $servers->method('get')->willReturn(new DbServer($serverVersion, [], 0));
        $catalogs = $this->createStub(CatalogSizeProvider::class);
        $catalogs->method('get')->willReturn(new CatalogSize(['simple' => $products], 0, 1, 1, 4, 0, 0));
        return new BatchSizeCheck($deploymentConfig, $catalogs, $servers);
    }

    /**
     * @param mixed $batchSize Value of indexer/batch_size
     * @return DeploymentConfig
     */
    private function deploymentConfig(mixed $batchSize): DeploymentConfig
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(
            static fn (string $path, mixed $default = null): mixed =>
                $path === 'indexer/batch_size' ? ($batchSize ?? $default) : $default
        );
        return $config;
    }
}
