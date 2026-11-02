<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Magento\Framework\Indexer\ConfigInterface;
use Orangecat\IndexerBoost\Model\Doctor\Check\DataExportersCheck;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use PHPUnit\Framework\TestCase;

class DataExportersCheckTest extends TestCase
{
    public function testNoExporterIndexersIsOk(): void
    {
        $check = $this->check([
            'catalog_product_price' => ['action_class' => 'Magento\Catalog\Model\Indexer\Product\Price'],
        ]);

        $results = $check->check(new DoctorContext());

        $this->assertSame('DATA_EXPORTERS', $check->getCode());
        $this->assertSame(
            ['ok', 'No Adobe Commerce Services data exporter indexers installed'],
            [$results[0]->toArray()['severity'], $results[0]->toArray()['message']]
        );
    }

    public function testExporterIndexersAreListedWithTheirModules(): void
    {
        $check = $this->check([
            'catalog_product_price' => ['action_class' => 'Magento\Catalog\Model\Indexer\Product\Price'],
            'sales_order_data_exporter' => [
                'action_class' => 'Magento\SalesDataExporter\Model\Indexer\OrdersFeedIndexer',
            ],
            'sales_order_status_data_exporter' => [
                'action_class' => 'Magento\SalesDataExporter\Model\Indexer\OrderStatusesFeedIndexer',
            ],
            'store_data_exporter' => ['action_class' => 'Magento\StoreDataExporter\Model\Indexer\StoreFeed'],
        ]);

        $results = $check->check(new DoctorContext());

        $this->assertSame(
            [
                'severity' => 'info',
                'code' => 'DATA_EXPORTERS',
                'message' => '3 data exporter indexer(s): sales_order_data_exporter, sales_order_status_data_exporter, '
                    . 'store_data_exporter',
                'recommendation' => 'These indexers feed Adobe Commerce Services (Catalog Service, Live Search, '
                    . 'Product Recommendations, Payment Services...). If none of those services is used, they '
                    . 'reindex for nothing: disable their modules after checking dependencies.',
                'fix' => 'bin/magento module:disable Magento_SalesDataExporter Magento_StoreDataExporter',
                'metrics' => [
                    'indexers' => [
                        'sales_order_data_exporter',
                        'sales_order_status_data_exporter',
                        'store_data_exporter',
                    ],
                    'modules' => ['Magento_SalesDataExporter', 'Magento_StoreDataExporter'],
                ],
            ],
            $results[0]->toArray()
        );
    }

    /**
     * @param array<string,array<string,mixed>> $indexers
     * @return DataExportersCheck
     */
    private function check(array $indexers): DataExportersCheck
    {
        $config = $this->createStub(ConfigInterface::class);
        $config->method('getIndexers')->willReturn($indexers);
        return new DataExportersCheck($config);
    }
}
