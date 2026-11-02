<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration\Indexer;

use Magento\Catalog\Model\Indexer\Product\Eav\Action\Full as CoreFull;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Eav\BatchSizeCalculator;
use Magento\Catalog\Test\Fixture\Attribute as AttributeFixture;
use Magento\Catalog\Test\Fixture\MultiselectAttribute as MultiselectAttributeFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Catalog\Test\Fixture\SelectAttribute as SelectAttributeFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBoost\Model\Indexer\ProductEav\Action\Full;
use PHPUnit\Framework\TestCase;

/**
 * The Indexer Boost full catalog_product_attribute reindex writes the same rows as the core one
 *
 * Batches of two products, so several batches run and a parent and its children fall in different ones. Catalog with
 * select and multiselect values by store view, a disabled, a not visible and a disabled-in-one-store product, and a
 * parent whose children carry the values.
 */
#[DbIsolation(false)]
class ProductEavFullIdentityTest extends TestCase
{
    private const BATCH_SIZE = 2;

    /**
     * @var AdapterInterface
     */
    private AdapterInterface $connection;

    #[
        DataFixture(\Magento\Store\Test\Fixture\Store::class, as: 'store2'),
        DataFixture(SelectAttributeFixture::class, ['is_filterable' => 1, 'scope' => 'store'], 'sel'),
        DataFixture(MultiselectAttributeFixture::class, ['is_filterable' => 1, 'scope' => 'store'], 'multi'),
        DataFixture(
            AttributeFixture::class,
            ['is_filterable' => 1, 'scope' => 'store', 'backend_type' => 'decimal', 'frontend_input' => 'price'],
            'dec'
        ),
        DataFixture(ProductFixture::class, [], 'p1'),
        DataFixture(ProductFixture::class, [], 'p2'),
        DataFixture(ProductFixture::class, ['visibility' => Visibility::VISIBILITY_NOT_VISIBLE], 'p3'),
        DataFixture(ProductFixture::class, ['status' => Status::STATUS_DISABLED], 'p4'),
        DataFixture(ProductFixture::class, [], 'p5'),
        DataFixture(ProductFixture::class, ['visibility' => Visibility::VISIBILITY_NOT_VISIBLE], 'p6'),
        DataFixture(ProductFixture::class, ['visibility' => Visibility::VISIBILITY_NOT_VISIBLE], 'p7'),
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
        $option = function (string $attribute, string $label) use ($fixtures): int {
            $model = $fixtures->get($attribute);
            $this->assertInstanceOf(DataObject::class, $model, $attribute);
            return (int)$model->getData($label);
        };
        $store2 = $id('store2');
        $sel = $id('sel');
        $multi = $id('multi');
        $dec = $id('dec');

        $int = $this->connection->getTableName('catalog_product_entity_int');
        $text = $this->connection->getTableName('catalog_product_entity_text');
        $relation = $this->connection->getTableName('catalog_product_relation');
        $this->connection->insertMultiple($int, [
            $this->value($sel, 0, $id('p1'), $option('sel', 'option_1')),
            $this->value($sel, $store2, $id('p1'), $option('sel', 'option_2')),
            $this->value($sel, 0, $id('p2'), $option('sel', 'option_1')),
            $this->value($sel, 0, $id('p3'), $option('sel', 'option_1')),
            $this->value($sel, 0, $id('p4'), $option('sel', 'option_2')),
            $this->value($sel, 0, $id('p6'), $option('sel', 'option_1')),
            $this->value($sel, 0, $id('p7'), $option('sel', 'option_2')),
        ]);
        $both = $option('multi', 'option_1') . ',' . $option('multi', 'option_2');
        $this->connection->insertMultiple($text, [
            $this->value($multi, 0, $id('p1'), $both),
            $this->value($multi, $store2, $id('p2'), (string)$option('multi', 'option_2')),
            $this->value($multi, 0, $id('p6'), (string)$option('multi', 'option_1')),
        ]);
        $this->connection->insertMultiple($this->connection->getTableName('catalog_product_entity_decimal'), [
            $this->value($dec, 0, $id('p1'), '1.5000'),
            $this->value($dec, $store2, $id('p1'), '2.2500'),
            $this->value($dec, 0, $id('p3'), '3.0000'),
            $this->value($dec, 0, $id('p6'), '4.0000'),
        ]);
        $statusId = (int)$this->connection->fetchOne(
            "SELECT attribute_id FROM {$this->connection->getTableName('eav_attribute')}"
            . " WHERE entity_type_id = 4 AND attribute_code = 'status'"
        );
        $this->connection->insertOnDuplicate(
            $int,
            [$this->value($statusId, $store2, $id('p7'), Status::STATUS_DISABLED)],
            ['value']
        );
        // p5 is the parent of p6, which is in another batch, and of p7
        $this->connection->insertMultiple($relation, [
            ['parent_id' => $id('p5'), 'child_id' => $id('p6')],
            ['parent_id' => $id('p5'), 'child_id' => $id('p7')],
        ]);
        $batchSize = $objectManager->create(
            BatchSizeCalculator::class,
            ['batchSizes' => ['source' => self::BATCH_SIZE, 'decimal' => self::BATCH_SIZE]]
        );

        try {
            $objectManager->create(CoreFull::class, ['batchSizeCalculator' => $batchSize])->execute();
            $core = $this->dump();
            $objectManager->create(Full::class, ['batchSizeCalculator' => $batchSize])->execute();
            $boost = $this->dump();
        } finally {
            $this->connection->delete($relation, ['parent_id = ?' => $id('p5')]);
        }

        $attributes = array_unique(array_column($core['catalog_product_index_eav'], 'attribute_id'));
        $this->assertContains((string)$sel, $attributes);
        $this->assertContains((string)$multi, $attributes);
        $this->assertContains(
            (string)$dec,
            array_unique(array_column($core['catalog_product_index_eav_decimal'], 'attribute_id'))
        );
        $this->assertSame($core, $boost);
    }

    /**
     * Attribute value row; entity_id is the link field in Magento Open Source
     *
     * @param int $attributeId
     * @param int $storeId
     * @param int $productId
     * @param int|string $value
     * @return array<string,int|string>
     */
    private function value(int $attributeId, int $storeId, int $productId, int|string $value): array
    {
        return ['attribute_id' => $attributeId, 'store_id' => $storeId, 'entity_id' => $productId, 'value' => $value];
    }

    /**
     * Rows of both EAV index tables, in key order
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function dump(): array
    {
        $rows = [];
        foreach (['catalog_product_index_eav', 'catalog_product_index_eav_decimal'] as $table) {
            $rows[$table] = $this->connection->fetchAll(
                $this->connection->select()
                    ->from($this->connection->getTableName($table))
                    ->order(['entity_id', 'attribute_id', 'store_id', 'value', 'source_id'])
            );
        }
        return $rows;
    }
}
