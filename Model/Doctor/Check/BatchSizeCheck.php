<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Magento\Framework\App\DeploymentConfig;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSizeProvider;
use Orangecat\IndexerBoost\Model\Doctor\Db\DbServerProvider;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * indexer/batch_size overrides in env.php (indexer/batch_size/<indexer_id>[/<type>])
 */
class BatchSizeCheck implements CheckInterface
{
    public const CODE = 'BATCH_SIZE';
    private const CONFIG_PATH = 'indexer/batch_size';
    private const CATEGORY_PRODUCT = 'catalog_category_product';

    /**
     * Core default of indexer/batch_size/catalog_category_product (Catalog di.xml, batchRowsCount)
     */
    private const CATEGORY_PRODUCT_DEFAULT = 100000;

    /**
     * Largest catalog_category_product batch measured with a good plan on MariaDB
     */
    private const CATEGORY_PRODUCT_MAX = 1000;

    /**
     * @param DeploymentConfig $deploymentConfig
     * @param CatalogSizeProvider $catalogSizeProvider
     * @param DbServerProvider $dbServerProvider
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly CatalogSizeProvider $catalogSizeProvider,
        private readonly DbServerProvider $dbServerProvider
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * @inheritdoc
     */
    public function isHeavy(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function check(DoctorContext $context): array
    {
        $overrides = [];
        $invalid = [];
        foreach ($this->flatten((array)$this->deploymentConfig->get(self::CONFIG_PATH, [])) as $path => $value) {
            if (ctype_digit($value) && (int)$value > 0) {
                $overrides[$path] = (int)$value;
            } else {
                $invalid[$path] = $value;
            }
        }

        $results = array_filter([$this->checkCategoryProduct($context, $overrides)]);
        if ($invalid) {
            $values = [];
            foreach ($invalid as $path => $value) {
                $values[] = $path . '=' . $value;
            }
            $results[] = new CheckResult(
                Severity::Warn,
                self::CODE,
                'Invalid indexer/batch_size values (must be positive integers): ' . implode(', ', $values),
                'Invalid batch sizes make the indexer process zero or unexpected rows per batch. Fix or remove them.',
                'Edit indexer/batch_size in app/etc/env.php',
                ['invalid' => $invalid]
            );
        }

        if ($overrides) {
            $values = [];
            foreach ($overrides as $path => $value) {
                $values[] = $path . '=' . $value;
            }
            $results[] = new CheckResult(
                Severity::Info,
                self::CODE,
                'indexer/batch_size overrides: ' . implode(', ', $values),
                'Batch size trades memory for fewer, larger queries. Validate each override with the benchmark.',
                '',
                ['batch_size' => $overrides]
            );
            return $results;
        }

        return array_merge($results, [
            new CheckResult(
                Severity::Info,
                self::CODE,
                'No indexer/batch_size overrides in app/etc/env.php: core defaults are used '
                . '(e.g. 5000 rows per batch for catalog_product_price, 100000 for catalog_category_product)',
                'Batch size trades memory for fewer, larger queries. Keep the defaults until the benchmark shows '
                . 'a gain, then tune one indexer at a time.',
                "app/etc/env.php: 'indexer' => ['batch_size' => ['catalog_product_price' => "
                . "['default' => 5000], 'catalog_category_product' => 1000]]",
                ['batch_size' => []]
            ),
        ]);
    }

    /**
     * Batch of catalog_category_product on MariaDB: one IN() list holds every product ID of a batch
     *
     * On MariaDB 10.11 with 42 040 products the default batch made the root category query pick a block nested
     * loop over catalog_product_website: 1 258 s for the full reindex, 34 s with batches of 1 000 IDs, same output.
     *
     * @param DoctorContext $context
     * @param array<string,int> $overrides
     * @return CheckResult|null
     */
    private function checkCategoryProduct(DoctorContext $context, array $overrides): ?CheckResult
    {
        $batch = $overrides[self::CATEGORY_PRODUCT] ?? self::CATEGORY_PRODUCT_DEFAULT;
        $products = $this->catalogSizeProvider->get($context)->getProductCount();
        $mariaDb = $this->dbServerProvider->get($context)->isMariaDb();
        if (!$mariaDb || min($batch, $products) <= self::CATEGORY_PRODUCT_MAX) {
            return null;
        }
        return new CheckResult(
            Severity::Warn,
            self::CODE,
            sprintf(
                '%s reindexes up to %d products per batch (%d products): MariaDB can pick a nested-loop plan for '
                . 'that many IDs in one IN() list',
                self::CATEGORY_PRODUCT,
                $batch,
                $products
            ),
            'Measured on MariaDB 10.11 with 42 040 products: the full catalog_category_product reindex went from '
            . '1 258 s to 34 s with batches of 1 000 products, with the same index content. Setting '
            . 'optimizer_use_condition_selectivity = 1 (MARIADB_OPTIMIZER) fixes the same plan for the whole server. '
            . 'Validate either change with the benchmark.',
            sprintf(
                "app/etc/env.php: 'indexer' => ['batch_size' => ['%s' => %d]]",
                self::CATEGORY_PRODUCT,
                self::CATEGORY_PRODUCT_MAX
            ),
            ['batch' => $batch, 'products' => $products]
        );
    }

    /**
     * Flatten the batch size tree into "indexer[/type]" => value
     *
     * @param array<mixed> $tree
     * @param string $prefix
     * @return array<string,string>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            $path = $prefix . $key;
            if (is_array($value)) {
                $flat += $this->flatten($value, $path . '/');
            } else {
                $flat[$path] = is_scalar($value) ? (string)$value : '';
            }
        }
        return $flat;
    }
}
