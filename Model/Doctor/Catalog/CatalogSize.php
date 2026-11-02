<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Catalog;

/**
 * Size of the catalog, used as context by size-dependent checks
 */
class CatalogSize
{
    /**
     * @param array<string,int> $productsByType
     * @param int $categories
     * @param int $stores
     * @param int $websites
     * @param int $customerGroups
     * @param int $catalogRules
     * @param int $activeCatalogRules
     */
    public function __construct(
        private readonly array $productsByType,
        private readonly int $categories,
        private readonly int $stores,
        private readonly int $websites,
        private readonly int $customerGroups,
        private readonly int $catalogRules,
        private readonly int $activeCatalogRules
    ) {
    }

    /**
     * Total number of products
     *
     * @return int
     */
    public function getProductCount(): int
    {
        return array_sum($this->productsByType);
    }

    /**
     * Export as a plain array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'products' => $this->getProductCount(),
            'products_by_type' => $this->productsByType,
            'categories' => $this->categories,
            'stores' => $this->stores,
            'websites' => $this->websites,
            'customer_groups' => $this->customerGroups,
            'catalog_rules' => $this->catalogRules,
            'active_catalog_rules' => $this->activeCatalogRules,
        ];
    }
}
