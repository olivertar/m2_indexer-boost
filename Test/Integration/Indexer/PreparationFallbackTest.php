<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration\Indexer;

use Magento\Catalog\Model\Indexer\Category\Product\Action\Full as CoreFull;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\Store;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Orangecat\IndexerBoost\Model\Indexer\CategoryProduct\StoreSnapshot;
use Orangecat\IndexerBoost\Model\Indexer\PreparationException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A failure while preparing the rewrite falls back to the core indexer through the real plugin and Switcher
 *
 * The product snapshot of the rewrite fails on its first store view: by then only the replica tables were touched,
 * so the core run that follows writes the whole index.
 */
#[DbIsolation(false)]
class PreparationFallbackTest extends TestCase
{
    private const FLAG = 'indexer_boost/catalog_category_product/';

    /**
     * @var TestHandler
     */
    private TestHandler $log;

    protected function setUp(): void
    {
        $snapshot = $this->createMock(StoreSnapshot::class);
        // Called at least once: the reindex went through the rewrite before falling back
        $snapshot->expects($this->atLeastOnce())->method('products')->willThrowException(
            new PreparationException('orangecat_ib_cp_product_1: snapshot unavailable')
        );
        $objectManager = $this->objectManager();
        // Our Full is created by a factory on every run, so it receives this shared instance
        $objectManager->addSharedInstance($snapshot, StoreSnapshot::class);
        $this->log = new TestHandler();
        $this->logger()->pushHandler($this->log);
    }

    protected function tearDown(): void
    {
        $objectManager = $this->objectManager();
        $objectManager->removeSharedInstance(StoreSnapshot::class);
        $this->logger()->popHandler();
    }

    /**
     * Object manager of the integration framework, which can replace shared instances
     *
     * @return ObjectManager
     */
    private function objectManager(): ObjectManager
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->assertInstanceOf(ObjectManager::class, $objectManager);
        return $objectManager;
    }

    /**
     * The shared logger the Switcher writes to (the integration framework replaces it with its own Monolog logger)
     *
     * @return Logger
     */
    private function logger(): Logger
    {
        $logger = Bootstrap::getObjectManager()->get(LoggerInterface::class);
        $this->assertInstanceOf(Logger::class, $logger);
        return $logger;
    }

    #[
        DataFixture(CategoryFixture::class, as: 'category'),
        DataFixture(ProductFixture::class, ['category_ids' => ['$category.id$']], 'product'),
        Config(self::FLAG . 'enabled', '1'),
        Config(self::FLAG . 'fallback_on_error', '1'),
    ]
    public function testTheCoreIndexerRunsAndTheFailureIsLogged(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $config = $objectManager->get(MutableScopeConfigInterface::class);
        $config->setValue(self::FLAG . 'enabled', '0');
        $objectManager->create(CoreFull::class)->execute();
        $expected = $this->index();
        $config->setValue(self::FLAG . 'enabled', '1');
        $this->connection()->truncateTable($this->connection()->getTableName('catalog_category_product_index_store1'));

        $objectManager->create(CoreFull::class)->execute();

        $this->assertNotSame([], $expected);
        $this->assertSame($expected, $this->index());
        $this->assertTrue(
            $this->log->hasErrorThatContains('preparing catalog_category_product failed'),
            'the fallback is logged'
        );
        $this->assertTrue($this->log->hasErrorThatContains('snapshot unavailable'));
    }

    #[
        DataFixture(CategoryFixture::class, as: 'category'),
        DataFixture(ProductFixture::class, ['category_ids' => ['$category.id$']], 'product'),
        Config(self::FLAG . 'enabled', '1'),
        Config(self::FLAG . 'fallback_on_error', '0'),
    ]
    public function testWithoutFallbackTheFailureStopsTheReindex(): void
    {
        $this->expectException(PreparationException::class);

        Bootstrap::getObjectManager()->create(CoreFull::class)->execute();
    }

    /**
     * Rows of the default store view index, in key order
     *
     * @return array<int,array<string,mixed>>
     */
    private function index(): array
    {
        $table = $this->connection()->getTableName('catalog_category_product_index_store' . Store::DISTRO_STORE_ID);
        return $this->connection()->fetchAll(
            $this->connection()->select()->from($table)->order(['category_id', 'product_id'])
        );
    }

    /**
     * Default connection
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
    }
}
