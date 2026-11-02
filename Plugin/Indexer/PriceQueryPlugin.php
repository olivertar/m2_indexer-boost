<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Plugin\Indexer;

use Magento\Catalog\Model\ResourceModel\Product\Indexer\Price\Query\BaseFinalPrice;
use Magento\Framework\DB\Select;
use Orangecat\IndexerBoost\Model\Indexer\JoinOrder;
use Orangecat\IndexerBoost\Model\Indexer\Price\FullReindexScope;

/**
 * Cheaper plan for the base price select of the full catalog_product_price reindex that Indexer Boost runs
 *
 * MariaDB starts from customer_group (a few rows), so it looks every EAV value of the product up once per customer
 * group. Read after the product, website and EAV tables, the groups only multiply the result rows.
 */
class PriceQueryPlugin
{
    /**
     * @param FullReindexScope $scope
     * @param JoinOrder $joinOrder
     */
    public function __construct(
        private readonly FullReindexScope $scope,
        private readonly JoinOrder $joinOrder
    ) {
    }

    /**
     * Read customer groups after the product values
     *
     * @param BaseFinalPrice $subject
     * @param Select $result
     * @param array<mixed> $dimensions
     * @param string $productType
     * @param array<int|string> $entityIds
     * @return Select
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetQuery(
        BaseFinalPrice $subject,
        Select $result,
        array $dimensions,
        string $productType,
        array $entityIds = []
    ): Select {
        if ($this->scope->isActive()) {
            $this->joinOrder->after($result, 'cg');
        }
        return $result;
    }
}
