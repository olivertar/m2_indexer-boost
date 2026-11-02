<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Indexer\CategoryProduct;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Config;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\Store;
use Orangecat\IndexerBoost\Model\Indexer\PreparationException;

/**
 * Store view values the catalog_category_product selects resolve, written once per store into TEMPORARY tables
 *
 * The core selects resolve status and visibility of every product, and of the children of composite products, with
 * eight joins on catalog_product_entity_int, in every batch of every select. These tables hold the result for one
 * store view, so the selects join them once by primary key. They are rebuilt by every full reindex on the connection
 * that reindexes the store, like the core temporary tables.
 */
class StoreSnapshot
{
    private const PRODUCT_TABLE = 'orangecat_ib_cp_product_';
    private const CATEGORY_TABLE = 'orangecat_ib_cp_category_';

    /**
     * @param Config $config
     * @param MetadataPool $metadataPool
     */
    public function __construct(
        private readonly Config $config,
        private readonly MetadataPool $metadataPool
    ) {
    }

    /**
     * Products of the store website, enabled and visible in the store view, as a TEMPORARY table
     *
     * @param AdapterInterface $connection
     * @param Store $store
     * @param int[] $visibleIds visibility values that count as visible
     * @return string table name
     * @throws PreparationException when the table cannot be built: nothing of the index was written yet
     */
    public function products(AdapterInterface $connection, Store $store, array $visibleIds): string
    {
        return $this->prepare(
            $connection->getTableName(self::PRODUCT_TABLE . (int)$store->getId()),
            fn (): string => $this->buildProducts($connection, $store, $visibleIds)
        );
    }

    /**
     * Categories with is_active and is_anchor of the store view, as a TEMPORARY table
     *
     * @param AdapterInterface $connection
     * @param Store $store
     * @return string table name
     * @throws PreparationException when the table cannot be built: nothing of the index was written yet
     */
    public function categories(AdapterInterface $connection, Store $store): string
    {
        return $this->prepare(
            $connection->getTableName(self::CATEGORY_TABLE . (int)$store->getId()),
            fn (): string => $this->buildCategories($connection, $store)
        );
    }

    /**
     * Build one snapshot; a failure is a preparation failure, so the Switcher may run the core indexer instead
     *
     * @param string $tableName
     * @param callable $build returns the table name
     * @return string
     * @throws PreparationException
     */
    private function prepare(string $tableName, callable $build): string
    {
        try {
            return $build();
        } catch (\Exception $e) {
            throw new PreparationException($tableName . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Products of the store website, enabled and visible in the store view
     *
     * Columns: entity_id, visibility (store view value), children_enabled (1 when the product has no child in
     * catalog_product_relation or at least one child enabled in the store view: the filter the core adds to the
     * anchor and non-anchor selects, not to the root category one).
     *
     * @param AdapterInterface $connection the connection that reindexes the store
     * @param Store $store
     * @param int[] $visibleIds visibilities "visible in site"
     * @return string table name
     * @throws \Exception when the product metadata is missing
     */
    private function buildProducts(AdapterInterface $connection, Store $store, array $visibleIds): string
    {
        $storeId = (int)$store->getId();
        $tableName = $connection->getTableName(self::PRODUCT_TABLE . $storeId);
        $table = $connection->newTable($tableName)
            ->addColumn('entity_id', Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false])
            ->addColumn('visibility', Table::TYPE_SMALLINT, null, ['unsigned' => true, 'nullable' => false])
            ->addColumn('children_enabled', Table::TYPE_SMALLINT, null, ['unsigned' => true, 'nullable' => false])
            ->addIndex('PRIMARY', ['entity_id'], ['type' => AdapterInterface::INDEX_TYPE_PRIMARY]);
        $connection->dropTemporaryTable($tableName);
        $connection->createTemporaryTable($table);

        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $statusId = (int)$this->config->getAttribute(Product::ENTITY, 'status')->getId();
        $visibilityId = (int)$this->config->getAttribute(Product::ENTITY, 'visibility')->getId();
        $intTable = $connection->getTableName('catalog_product_entity_int');
        $entityTable = $connection->getTableName('catalog_product_entity');
        $relationTable = $connection->getTableName('catalog_product_relation');
        $status = $connection->getIfNullSql('cpss.value', 'cpsd.value');
        $visibility = $connection->getIfNullSql('cpvs.value', 'cpvd.value');

        // Same joins as AbstractAction::addFilteringByChildProductsToSelect(), per parent instead of per row
        $anyChild = $connection->select()
            ->from(['relation' => $relationTable], ['found' => new \Zend_Db_Expr('1')])
            ->where('relation.parent_id = cpe.' . $linkField);
        $enabledChild = $connection->select()
            ->from(['relation' => $relationTable], ['found' => new \Zend_Db_Expr('1')])
            ->joinInner(['child' => $entityTable], 'relation.child_id = child.entity_id', [])
            ->joinInner(
                ['child_cpsd' => $intTable],
                'child_cpsd.' . $linkField . ' = child.' . $linkField
                . ' AND child_cpsd.store_id = 0 AND child_cpsd.attribute_id = ' . $statusId,
                []
            )->joinLeft(
                ['child_cpss' => $intTable],
                'child_cpss.' . $linkField . ' = child.' . $linkField
                . ' AND child_cpss.attribute_id = child_cpsd.attribute_id AND child_cpss.store_id = ' . $storeId,
                []
            )->where('relation.parent_id = cpe.' . $linkField)
            ->where(
                $connection->getIfNullSql('child_cpss.value', 'child_cpsd.value') . ' = ?',
                Status::STATUS_ENABLED
            );

        // From the website products: from catalog_product_entity, MariaDB joins catalog_product_website last, by a
        // range on website_id, through a block nested loop (seconds per store become minutes)
        $select = $connection->select()
            ->from(['cpw' => $connection->getTableName('catalog_product_website')], [])
            ->joinInner(['cpe' => $entityTable], 'cpe.entity_id = cpw.product_id', [])
            ->joinInner(
                ['cpsd' => $intTable],
                'cpsd.' . $linkField . ' = cpe.' . $linkField . ' AND cpsd.store_id = 0 AND cpsd.attribute_id = '
                . $statusId,
                []
            )->joinLeft(
                ['cpss' => $intTable],
                'cpss.' . $linkField . ' = cpe.' . $linkField
                . ' AND cpss.attribute_id = cpsd.attribute_id AND cpss.store_id = ' . $storeId,
                []
            )->joinInner(
                ['cpvd' => $intTable],
                'cpvd.' . $linkField . ' = cpe.' . $linkField . ' AND cpvd.store_id = 0 AND cpvd.attribute_id = '
                . $visibilityId,
                []
            )->joinLeft(
                ['cpvs' => $intTable],
                'cpvs.' . $linkField . ' = cpe.' . $linkField
                . ' AND cpvs.attribute_id = cpvd.attribute_id AND cpvs.store_id = ' . $storeId,
                []
            )->where('cpw.website_id = ?', (int)$store->getWebsiteId())
            ->where($status . ' = ?', Status::STATUS_ENABLED)
            ->where($visibility . ' IN (?)', $visibleIds)
            ->columns([
                'entity_id' => 'cpe.entity_id',
                'visibility' => $visibility,
                'children_enabled' => new \Zend_Db_Expr(
                    sprintf('NOT EXISTS (%s) OR EXISTS (%s)', $anyChild, $enabledChild)
                ),
            ]);

        $connection->query(
            $connection->insertFromSelect(
                $select,
                $tableName,
                ['entity_id', 'visibility', 'children_enabled'],
                AdapterInterface::INSERT_ON_DUPLICATE
            )
        );
        return $tableName;
    }

    /**
     * Categories with a default is_active value, with is_active and is_anchor of the store view
     *
     * Same joins as the core selects: is_active needs its default row (inner join) and is_anchor is NULL without
     * one, so such a category is never anchor, as in the core anchor select.
     *
     * @param AdapterInterface $connection the connection that reindexes the store
     * @param Store $store
     * @return string table name
     * @throws \Exception when the category metadata is missing
     */
    private function buildCategories(AdapterInterface $connection, Store $store): string
    {
        $storeId = (int)$store->getId();
        $tableName = $connection->getTableName(self::CATEGORY_TABLE . $storeId);
        $table = $connection->newTable($tableName)
            ->addColumn('entity_id', Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false])
            ->addColumn('is_active', Table::TYPE_SMALLINT, null, ['nullable' => true])
            ->addColumn('is_anchor', Table::TYPE_SMALLINT, null, ['nullable' => true])
            ->addIndex('PRIMARY', ['entity_id'], ['type' => AdapterInterface::INDEX_TYPE_PRIMARY]);
        $connection->dropTemporaryTable($tableName);
        $connection->createTemporaryTable($table);

        $linkField = $this->metadataPool->getMetadata(CategoryInterface::class)->getLinkField();
        $isActiveId = (int)$this->config->getAttribute(Category::ENTITY, 'is_active')->getId();
        $isAnchorId = (int)$this->config->getAttribute(Category::ENTITY, 'is_anchor')->getId();
        $intTable = $connection->getTableName('catalog_category_entity_int');

        $select = $connection->select()
            ->from(['cc' => $connection->getTableName('catalog_category_entity')], [])
            ->joinInner(
                ['ccacd' => $intTable],
                'ccacd.' . $linkField . ' = cc.' . $linkField . ' AND ccacd.store_id = 0 AND ccacd.attribute_id = '
                . $isActiveId,
                []
            )->joinLeft(
                ['ccacs' => $intTable],
                'ccacs.' . $linkField . ' = cc.' . $linkField
                . ' AND ccacs.attribute_id = ccacd.attribute_id AND ccacs.store_id = ' . $storeId,
                []
            )->joinLeft(
                ['ccad' => $intTable],
                'ccad.' . $linkField . ' = cc.' . $linkField . ' AND ccad.store_id = 0 AND ccad.attribute_id = '
                . $isAnchorId,
                []
            )->joinLeft(
                ['ccas' => $intTable],
                'ccas.' . $linkField . ' = cc.' . $linkField
                . ' AND ccas.attribute_id = ccad.attribute_id AND ccas.store_id = ' . $storeId,
                []
            )->columns([
                'entity_id' => 'cc.entity_id',
                'is_active' => $connection->getIfNullSql('ccacs.value', 'ccacd.value'),
                'is_anchor' => $connection->getIfNullSql('ccas.value', 'ccad.value'),
            ]);

        $connection->query(
            $connection->insertFromSelect(
                $select,
                $tableName,
                ['entity_id', 'is_active', 'is_anchor'],
                AdapterInterface::INSERT_ON_DUPLICATE
            )
        );
        return $tableName;
    }
}
