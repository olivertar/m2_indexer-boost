<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSize;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSizeProvider;
use Orangecat\IndexerBoost\Model\Doctor\Check\PriceDimensionsCheck;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PriceDimensionsCheckTest extends TestCase
{
    public function testSmallCatalogIsNotEvaluated(): void
    {
        $check = $this->check('none', 49999, 3, 10);

        $results = $check->check(new DoctorContext());

        $this->assertSame('PRICE_DIMENSIONS', $check->getCode());
        $this->assertSame(
            ['ok', 'Not evaluated below 50000 products (current mode: none)'],
            [$results[0]->toArray()['severity'], $results[0]->toArray()['message']]
        );
    }

    public function testLargeCatalogWithSeveralWebsitesAndNoDimensionsIsWarn(): void
    {
        $results = $this->check(null, 50000, 2, 3)->check(new DoctorContext());

        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'PRICE_DIMENSIONS',
                'message' => 'catalog_product_price dimensions mode is "none" with 50000 products, 2 websites '
                    . 'and 3 customer groups',
                'recommendation' => 'Dimension modes split the price index into one table per website and '
                    . 'customer group, so each reindex works on smaller tables and can run in parallel. Changing '
                    . 'the mode triggers a full price reindex: do it in a maintenance window and measure.',
                'fix' => 'bin/magento indexer:set-dimensions-mode catalog_product_price website_and_customer_group',
                'metrics' => ['mode' => 'none', 'products' => 50000, 'websites' => 2, 'customer_groups' => 3],
            ],
            $results[0]->toArray()
        );
    }

    #[DataProvider('okProvider')]
    public function testLargeCatalogWithoutNeedOrAlreadyDimensionedIsOk(
        string $mode,
        int $websites,
        int $groups,
        string $message
    ): void {
        $results = $this->check($mode, 80000, $websites, $groups)->check(new DoctorContext());

        $this->assertSame(['ok', $message], [$results[0]->toArray()['severity'], $results[0]->toArray()['message']]);
    }

    /**
     * @return array<string,array{string,int,int,string}>
     */
    public static function okProvider(): array
    {
        return [
            'already dimensioned' => [
                'website_and_customer_group',
                3,
                10,
                'catalog_product_price dimensions mode is "website_and_customer_group"',
            ],
            'one website, few groups' => [
                'none',
                1,
                3,
                'Dimensions mode "none" is fine with 1 website and 3 customer groups',
            ],
        ];
    }

    /**
     * @param string|null $mode
     * @param int $products
     * @param int $websites
     * @param int $groups
     * @return PriceDimensionsCheck
     */
    private function check(?string $mode, int $products, int $websites, int $groups): PriceDimensionsCheck
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string =>
                $path === 'indexer/catalog_product_price/dimensions_mode' ? $mode : null
        );
        $sizeProvider = $this->createStub(CatalogSizeProvider::class);
        $sizeProvider->method('get')->willReturn(
            new CatalogSize(['simple' => $products], 0, $websites, $websites, $groups, 0, 0)
        );

        return new PriceDimensionsCheck($scopeConfig, $sizeProvider, 50000, 1, 3);
    }
}
