<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration\Indexer;

use Magento\Catalog\Model\Indexer\Category\Product\Action\Full as CoreFull;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBoost\Model\Indexer\CategoryProduct\Action\Full;
use PHPUnit\Framework\TestCase;

/**
 * The Indexer Boost full catalog_category_product reindex writes the same rows as the core one
 *
 * Catalog with the cases the selects resolve: anchor and non-anchor categories nested, an inactive branch, store
 * view overrides of status, visibility, is_anchor and is_active, a product outside the website, a product in two
 * categories of one anchor branch, and a parent whose children are enabled in one store view only.
 */
#[DbIsolation(false)]
class CategoryProductFullIdentityTest extends TestCase
{
    /**
     * @var AdapterInterface
     */
    private AdapterInterface $connection;

    #[
        DataFixture(StoreFixture::class, as: 'store2'),
        DataFixture(CategoryFixture::class, ['is_anchor' => 1], 'a'),
        DataFixture(CategoryFixture::class, ['parent_id' => '$a.id$', 'is_anchor' => 0], 'b'),
        DataFixture(CategoryFixture::class, ['parent_id' => '$b.id$', 'is_anchor' => 1], 'c'),
        DataFixture(CategoryFixture::class, ['is_anchor' => 0], 'n'),
        DataFixture(CategoryFixture::class, ['is_anchor' => 1, 'is_active' => false], 'i'),
        DataFixture(CategoryFixture::class, ['parent_id' => '$i.id$', 'is_anchor' => 1], 'j'),
        DataFixture(ProductFixture::class, ['category_ids' => ['$c.id$']], 'p1'),
        DataFixture(ProductFixture::class, ['category_ids' => ['$b.id$']], 'p2'),
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$n.id$'], 'visibility' => Visibility::VISIBILITY_NOT_VISIBLE],
            'p3'
        ),
        DataFixture(
            ProductFixture::class,
            [
                'category_links' => [
                    ['category_id' => '$a.id$', 'position' => 7],
                    ['category_id' => '$c.id$', 'position' => 3],
                ],
            ],
            'p4'
        ),
        DataFixture(ProductFixture::class, ['category_ids' => ['$c.id$']], 'p5'),
        DataFixture(
            ProductFixture::class,
            ['status' => Status::STATUS_DISABLED, 'visibility' => Visibility::VISIBILITY_NOT_VISIBLE],
            'p6'
        ),
        DataFixture(ProductFixture::class, ['visibility' => Visibility::VISIBILITY_NOT_VISIBLE], 'p7'),
        DataFixture(ProductFixture::class, ['category_ids' => ['$j.id$']], 'p8'),
        DataFixture(ProductFixture::class, [], 'p9'),
        DataFixture(ProductFixture::class, ['category_ids' => ['$c.id$'], 'website_ids' => []], 'p10'),
    ]
    public function testBoostFullWritesTheSameIndexAsTheCore(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->connection = $objectManager->get(ResourceConnection::class)->getConnection();
        $fixtures = DataFixtureStorageManager::getStorage();
        $id = function (string $name) use ($fixtures): int {
            $model = $fixtures->get($name);
            $this->assertInstanceOf(AbstractModel::class, $model, $name);
            return (int)$model->getId();
        };
        $store2 = $id('store2');

        $this->setProductInt($id('p2'), $store2, 'status', Status::STATUS_DISABLED);
        $this->setProductInt($id('p3'), $store2, 'visibility', Visibility::VISIBILITY_IN_CATALOG);
        $this->setProductInt($id('p7'), $store2, 'status', Status::STATUS_DISABLED);
        $this->setCategoryInt($id('b'), $store2, 'is_anchor', 1);
        $this->setCategoryInt($id('n'), $store2, 'is_active', 0);
        $relation = $this->connection->getTableName('catalog_product_relation');
        $this->connection->insertMultiple($relation, [
            ['parent_id' => $id('p5'), 'child_id' => $id('p6')],
            ['parent_id' => $id('p5'), 'child_id' => $id('p7')],
        ]);
        $storeIds = array_map(
            static fn ($store): int => (int)$store->getId(),
            array_values($objectManager->get(StoreManagerInterface::class)->getStores())
        );

        try {
            $objectManager->create(CoreFull::class)->execute();
            $core = $this->dump($storeIds);
            $objectManager->create(Full::class)->execute();
            $boost = $this->dump($storeIds);
        } finally {
            $this->connection->delete($relation, ['parent_id = ?' => $id('p5')]);
        }

        $this->assertNotSame($core[1], $core[$store2], 'the store view overrides change the index');
        $this->assertSame($core, $boost);
    }

    public function testSelectsReadTheStoreSnapshotInsteadOfTheAttributeTables(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $store = $objectManager->get(StoreManagerInterface::class)->getStore(1);
        $full = $objectManager->create(Full::class);

        foreach (['getAllProducts', 'getAnchorCategoriesSelect', 'getNonAnchorCategoriesSelect'] as $method) {
            $sql = (string)(new \ReflectionMethod($full, $method))->invoke($full, $store);

            $this->assertStringContainsString('orangecat_ib_cp_product_1', $sql, $method);
            $this->assertStringNotContainsString('catalog_product_entity_int', $sql, $method);
            if ($method !== 'getAllProducts') {
                $this->assertStringContainsString('orangecat_ib_cp_category_1', $sql, $method);
                $this->assertStringNotContainsString('catalog_category_entity_int', $sql, $method);
            }
        }
    }

    /**
     * Index rows of every store, in key order
     *
     * @param int[] $storeIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function dump(array $storeIds): array
    {
        $rows = [];
        foreach ($storeIds as $storeId) {
            $rows[$storeId] = $this->connection->fetchAll(
                $this->connection->select()
                    ->from($this->connection->getTableName('catalog_category_product_index_store' . $storeId))
                    ->order(['category_id', 'product_id'])
            );
        }
        return $rows;
    }

    /**
     * Store view value of a product int attribute
     *
     * @param int $productId
     * @param int $storeId
     * @param string $attributeCode
     * @param int $value
     * @return void
     */
    private function setProductInt(int $productId, int $storeId, string $attributeCode, int $value): void
    {
        $this->setInt('catalog_product_entity_int', 'catalog_product', $productId, $storeId, $attributeCode, $value);
    }

    /**
     * Store view value of a category int attribute
     *
     * @param int $categoryId
     * @param int $storeId
     * @param string $attributeCode
     * @param int $value
     * @return void
     */
    private function setCategoryInt(int $categoryId, int $storeId, string $attributeCode, int $value): void
    {
        $this->setInt('catalog_category_entity_int', 'catalog_category', $categoryId, $storeId, $attributeCode, $value);
    }

    /**
     * Write an int attribute value for one store view (entity_id is the link field in Magento Open Source)
     *
     * @param string $table
     * @param string $entityType
     * @param int $entityId
     * @param int $storeId
     * @param string $attributeCode
     * @param int $value
     * @return void
     */
    private function setInt(
        string $table,
        string $entityType,
        int $entityId,
        int $storeId,
        string $attributeCode,
        int $value
    ): void {
        $attributeId = (int)Bootstrap::getObjectManager()->get(EavConfig::class)
            ->getAttribute($entityType, $attributeCode)->getId();
        $this->connection->insertOnDuplicate(
            $this->connection->getTableName($table),
            ['attribute_id' => $attributeId, 'store_id' => $storeId, 'entity_id' => $entityId, 'value' => $value],
            ['value']
        );
    }
}
