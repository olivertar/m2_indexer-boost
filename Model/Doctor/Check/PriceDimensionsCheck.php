<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSizeProvider;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Dimensions mode of the catalog_product_price indexer
 */
class PriceDimensionsCheck implements CheckInterface
{
    public const CODE = 'PRICE_DIMENSIONS';
    private const CONFIG_PATH = 'indexer/catalog_product_price/dimensions_mode';
    private const RECOMMENDED_MODE = 'website_and_customer_group';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param CatalogSizeProvider $catalogSizeProvider
     * @param int $evaluateFromProducts
     * @param int $maxWebsitesWithoutDimensions
     * @param int $maxCustomerGroupsWithoutDimensions
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CatalogSizeProvider $catalogSizeProvider,
        private readonly int $evaluateFromProducts = 50000,
        private readonly int $maxWebsitesWithoutDimensions = 1,
        private readonly int $maxCustomerGroupsWithoutDimensions = 3
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
        $mode = (string)($this->scopeConfig->getValue(self::CONFIG_PATH) ?: 'none');
        $size = $this->catalogSizeProvider->get($context)->toArray();

        if ($size['products'] < $this->evaluateFromProducts) {
            return [
                new CheckResult(
                    Severity::Ok,
                    self::CODE,
                    sprintf('Not evaluated below %d products (current mode: %s)', $this->evaluateFromProducts, $mode)
                ),
            ];
        }

        if ($mode === self::RECOMMENDED_MODE) {
            return [
                new CheckResult(
                    Severity::Ok,
                    self::CODE,
                    sprintf('catalog_product_price dimensions mode is "%s"', $mode)
                ),
            ];
        }

        if ($size['websites'] <= $this->maxWebsitesWithoutDimensions
            && $size['customer_groups'] <= $this->maxCustomerGroupsWithoutDimensions
        ) {
            return [
                new CheckResult(
                    Severity::Ok,
                    self::CODE,
                    sprintf(
                        'Dimensions mode "%s" is fine with %d %s and %d customer groups',
                        $mode,
                        $size['websites'],
                        $size['websites'] === 1 ? 'website' : 'websites',
                        $size['customer_groups']
                    )
                ),
            ];
        }

        return [
            new CheckResult(
                Severity::Warn,
                self::CODE,
                sprintf(
                    'catalog_product_price dimensions mode is "%s" with %d products, %d websites '
                    . 'and %d customer groups',
                    $mode,
                    $size['products'],
                    $size['websites'],
                    $size['customer_groups']
                ),
                'Dimension modes split the price index into one table per website and customer group, so '
                . 'each reindex works on smaller tables and can run in parallel. Changing the mode triggers a '
                . 'full price reindex: do it in a maintenance window and measure.',
                'bin/magento indexer:set-dimensions-mode catalog_product_price ' . self::RECOMMENDED_MODE,
                [
                    'mode' => $mode,
                    'products' => $size['products'],
                    'websites' => $size['websites'],
                    'customer_groups' => $size['customer_groups'],
                ]
            ),
        ];
    }
}
