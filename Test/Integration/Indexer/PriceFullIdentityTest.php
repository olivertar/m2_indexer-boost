<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration\Indexer;

use Magento\Catalog\Model\Indexer\Product\Price\Action\Full as CoreFull;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Price\BatchSizeCalculator;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\ConfigurableProduct\Test\Fixture\Attribute as ConfigurableAttributeFixture;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProductFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBoost\Model\Indexer\Price\FullReindexScope;
use PHPUnit\Framework\TestCase;

/**
 * The core full catalog_product_price reindex writes the same rows inside the Indexer Boost scope as outside it
 *
 * Batches of two products. Catalog with special prices whose dates are overridden on the store view, tier prices for
 * one group, for all groups and by website, as percentage, a disabled and an out of stock product, and a configurable
 * product whose children have different prices.
 */
#[DbIsolation(false)]
class PriceFullIdentityTest extends TestCase
{
    private const BATCH_SIZE = 2;

    /**
     * @var AdapterInterface
     */
    private AdapterInterface $connection;

    #[
        DataFixture(ProductFixture::class, ['price' => 20], 'p1'),
        DataFixture(ProductFixture::class, ['price' => 30, 'custom_attributes' => ['special_price' => 25]], 'p2'),
        DataFixture(ProductFixture::class, ['price' => 40, 'custom_attributes' => ['special_price' => 35]], 'p3'),
        DataFixture(ProductFixture::class, ['price' => 50, 'status' => Status::STATUS_DISABLED], 'p4'),
        DataFixture(
            ProductFixture::class,
            ['price' => 60, 'extension_attributes' => ['stock_item' => ['is_in_stock' => false]]],
            'p5'
        ),
        DataFixture(ProductFixture::class, ['price' => 70, 'visibility' => Visibility::VISIBILITY_NOT_VISIBLE], 'c1'),
        DataFixture(ProductFixture::class, ['price' => 80, 'visibility' => Visibility::VISIBILITY_NOT_VISIBLE], 'c2'),
        DataFixture(ConfigurableAttributeFixture::class, as: 'attr'),
        DataFixture(
            ConfigurableProductFixture::class,
            ['_options' => ['$attr$'], '_links' => ['$c1$', '$c2$']],
            'conf'
        ),
    ]
    public function testScopeKeepsTheCoreIndex(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->connection = $objectManager->get(ResourceConnection::class)->getConnection();
        $fixtures = DataFixtureStorageManager::getStorage();
        $id = function (string $name) use ($fixtures): int {
            $model = $fixtures->get($name);
            $this->assertInstanceOf(AbstractModel::class, $model, $name);
            return (int)$model->getId();
        };

        $datetime = $this->connection->getTableName('catalog_product_entity_datetime');
        $tier = $this->connection->getTableName('catalog_product_entity_tier_price');
        $attribute = function (string $code): int {
            return (int)$this->connection->fetchOne(
                "SELECT attribute_id FROM {$this->connection->getTableName('eav_attribute')}"
                . ' WHERE entity_type_id = 4 AND attribute_code = ?',
                [$code]
            );
        };
        // p2's special price starts in the future by default and in the past on the default store view; p3's ended
        $this->connection->insertOnDuplicate($datetime, [
            $this->value($attribute('special_from_date'), 0, $id('p2'), '2999-01-01 00:00:00'),
            $this->value($attribute('special_from_date'), 1, $id('p2'), '2000-01-01 00:00:00'),
            $this->value($attribute('special_to_date'), 0, $id('p3'), '2000-01-01 00:00:00'),
        ], ['value']);
        $this->connection->insertMultiple($tier, [
            $this->tier($id('p1'), 0, 1, 0, 15.0, null),
            $this->tier($id('p1'), 1, 0, 1, 18.0, null),
            $this->tier($id('p3'), 1, 0, 0, null, 10.0),
            $this->tier($id('c1'), 0, 2, 1, 65.0, null),
        ]);
        $batchSize = $objectManager->create(
            BatchSizeCalculator::class,
            ['batchRowsCount' => ['default' => self::BATCH_SIZE]]
        );
        $scope = $objectManager->get(FullReindexScope::class);

        try {
            $objectManager->create(CoreFull::class, ['batchSizeCalculator' => $batchSize])->execute();
            $core = $this->dump();
            $scope->run(
                fn () => $objectManager->create(CoreFull::class, ['batchSizeCalculator' => $batchSize])->execute()
            );
            $boost = $this->dump();
        } finally {
            $this->connection->delete($tier, ['entity_id IN (?)' => [$id('p1'), $id('p3'), $id('c1')]]);
        }

        $indexed = array_map(fn (string $row): int => (int)strtok($row, '|'), $core);
        $this->assertContains($id('conf'), $indexed);
        $this->assertNotContains($id('p4'), $indexed);
        $this->assertNotContains($id('p5'), $indexed);
        $this->assertStringContainsString('|15.000000', implode("\n", $core));
        $this->assertSame($core, $boost);
    }

    /**
     * Attribute value row; entity_id is the link field in Magento Open Source
     *
     * @param int $attributeId
     * @param int $storeId
     * @param int $productId
     * @param string $value
     * @return array<string,int|string>
     */
    private function value(int $attributeId, int $storeId, int $productId, string $value): array
    {
        return ['attribute_id' => $attributeId, 'store_id' => $storeId, 'entity_id' => $productId, 'value' => $value];
    }

    /**
     * Tier price row for quantity one
     *
     * @param int $productId
     * @param int $allGroups
     * @param int $groupId
     * @param int $websiteId
     * @param float|null $value
     * @param float|null $percentage
     * @return array<string,int|float|null>
     */
    private function tier(
        int $productId,
        int $allGroups,
        int $groupId,
        int $websiteId,
        ?float $value,
        ?float $percentage
    ): array {
        return [
            'entity_id' => $productId,
            'all_groups' => $allGroups,
            'customer_group_id' => $groupId,
            'qty' => 1,
            'value' => $value ?? 0,
            'percentage_value' => $percentage,
            'website_id' => $websiteId,
        ];
    }

    /**
     * Rows of the price index, in key order, one string per row so a failure diff stays readable
     *
     * @return string[]
     */
    private function dump(): array
    {
        $rows = $this->connection->fetchAll(
            $this->connection->select()
                ->from($this->connection->getTableName('catalog_product_index_price'))
                ->order(['entity_id', 'customer_group_id', 'website_id'])
        );
        return array_map(fn (array $row): string => implode('|', $row), $rows);
    }
}
