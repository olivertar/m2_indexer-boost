<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Catalog;

use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;

/**
 * Counts catalog entities once per doctor run
 */
class CatalogSizeProvider
{
    /**
     * @param ReadOnlyConnection $connection
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection
    ) {
    }

    /**
     * Catalog size for the current doctor run
     *
     * @param DoctorContext $context
     * @return CatalogSize
     */
    public function get(DoctorContext $context): CatalogSize
    {
        return $context->remember(self::class, fn (): CatalogSize => $this->load());
    }

    /**
     * Query the catalog size
     *
     * @return CatalogSize
     */
    private function load(): CatalogSize
    {
        $productsByType = [];
        $pairs = $this->connection->fetchPairs(sprintf(
            'SELECT type_id, COUNT(*) FROM %s GROUP BY type_id',
            $this->connection->getTableName('catalog_product_entity')
        ));
        foreach ($pairs as $type => $count) {
            $productsByType[(string)$type] = (int)$count;
        }
        $rules = $this->connection->fetchRow(sprintf(
            'SELECT COUNT(*) AS total, COALESCE(SUM(is_active), 0) AS active FROM %s',
            $this->connection->getTableName('catalogrule')
        )) ?: ['total' => 0, 'active' => 0];

        return new CatalogSize(
            $productsByType,
            $this->count('catalog_category_entity', 'parent_id > 0'),
            $this->count('store', 'store_id > 0'),
            $this->count('store_website', 'website_id > 0'),
            $this->count('customer_group'),
            (int)$rules['total'],
            (int)$rules['active']
        );
    }

    /**
     * Count rows of a table
     *
     * @param string $table
     * @param string $where Static condition, never user input
     * @return int
     */
    private function count(string $table, string $where = '1 = 1'): int
    {
        return (int)$this->connection->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s',
            $this->connection->getTableName($table),
            $where
        ));
    }
}
