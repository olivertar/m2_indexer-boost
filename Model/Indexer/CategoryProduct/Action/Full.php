<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Indexer\CategoryProduct\Action;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Config;
use Magento\Catalog\Model\Indexer\Category\Product;
use Magento\Catalog\Model\Indexer\Category\Product\Action\Full as CoreFull;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Indexer\ActiveTableSwitcher;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Query\Generator as QueryGenerator;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Indexer\BatchProviderInterface;
use Magento\Framework\Indexer\BatchSizeManagementInterface;
use Magento\Indexer\Model\ProcessManager;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Orangecat\IndexerBoost\Model\Indexer\CategoryProduct\BatchWriter;
use Orangecat\IndexerBoost\Model\Indexer\CategoryProduct\StoreSnapshot;

/**
 * Full catalog_category_product reindex of the core, with the batch loop of BatchWriter and selects on StoreSnapshot
 *
 * The core loop, Full::reindexCategoriesBySelect(), is private, so the three protected methods that call it are
 * overridden with the same body. The three selects read the store view values from the StoreSnapshot tables instead
 * of resolving them with the attribute tables in every batch. Everything else (tables, stores in parallel, category
 * tree, table switch) is the core.
 */
class Full extends CoreFull
{
    private const DEPLOYMENT_CONFIG_INDEXER_BATCHES = 'indexer/batch_size/';

    /**
     * @var int batch size of the core, used when env.php does not set indexer/batch_size/catalog_category_product
     */
    private int $batchRowsCount;

    /**
     * @var array<int,string> product snapshot table per store ID, on the connection that reindexes the store
     */
    private array $productSnapshots = [];

    /**
     * @var array<int,string> category snapshot table per store ID
     */
    private array $categorySnapshots = [];

    /**
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     * @param QueryGenerator $queryGenerator
     * @param BatchSizeManagementInterface $batchSizeManagement
     * @param BatchProviderInterface $batchProvider
     * @param MetadataPool $metadataPool
     * @param int|string $batchRowsCount the di.xml number arrives as a string
     * @param ActiveTableSwitcher $activeTableSwitcher
     * @param ProcessManager $processManager
     * @param DeploymentConfig $deploymentConfig
     * @param BatchWriter $batchWriter
     * @param StoreSnapshot $storeSnapshot
     * @param Visibility $productVisibility
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) same arguments as the core action, plus our collaborators
     */
    public function __construct(
        ResourceConnection $resource,
        StoreManagerInterface $storeManager,
        Config $config,
        QueryGenerator $queryGenerator,
        private readonly BatchSizeManagementInterface $batchSizeManagement,
        BatchProviderInterface $batchProvider,
        MetadataPool $metadataPool,
        int|string $batchRowsCount,
        ActiveTableSwitcher $activeTableSwitcher,
        ProcessManager $processManager,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly BatchWriter $batchWriter,
        private readonly StoreSnapshot $storeSnapshot,
        private readonly Visibility $productVisibility
    ) {
        parent::__construct(
            $resource,
            $storeManager,
            $config,
            $queryGenerator,
            $batchSizeManagement,
            $batchProvider,
            $metadataPool,
            (int)$batchRowsCount,
            $activeTableSwitcher,
            $processManager,
            $deploymentConfig
        );
        $this->batchRowsCount = (int)$batchRowsCount;
    }

    /**
     * @inheritdoc
     */
    protected function reindexRootCategory(Store $store): void
    {
        if ($this->isIndexRootCategoryNeeded()) {
            $this->reindexCategoriesBySelect($this->getAllProducts($store), 'cp.entity_id IN (?)', $store);
        }
    }

    /**
     * @inheritdoc
     */
    protected function reindexAnchorCategories(Store $store): void
    {
        $this->reindexCategoriesBySelect($this->getAnchorCategoriesSelect($store), 'ccp.product_id IN (?)', $store);
    }

    /**
     * @inheritdoc
     */
    protected function reindexNonAnchorCategories(Store $store): void
    {
        $this->reindexCategoriesBySelect($this->getNonAnchorCategoriesSelect($store), 'ccp.product_id IN (?)', $store);
    }

    /**
     * Every product of the store website, enabled and visible, in the root category
     *
     * Same rows as the core select: the snapshot holds the status and visibility filters, and the root category takes
     * no filter on the children of composite products.
     *
     * @param Store $store
     * @return Select
     * @throws \Exception
     */
    protected function getAllProducts(Store $store)
    {
        $storeId = (int)$store->getId();
        if (!isset($this->productsSelects[$storeId])) {
            $this->productsSelects[$storeId] = $this->connection->select()
                ->from(['cp' => $this->productSnapshot($store)], [])
                ->joinLeft(
                    ['ccp' => $this->getTable('catalog_category_product')],
                    'ccp.product_id = cp.entity_id',
                    []
                )->group('cp.entity_id')
                ->columns([
                    'category_id' => new \Zend_Db_Expr((string)$store->getRootCategoryId()),
                    'product_id' => 'cp.entity_id',
                    'position' => $this->connection->getCheckSql(
                        'ccp.product_id IS NOT NULL',
                        'MIN(ccp.position)',
                        '10000'
                    ),
                    'is_parent' => $this->connection->getCheckSql('ccp.product_id IS NOT NULL', '1', '0'),
                    'store_id' => new \Zend_Db_Expr((string)$storeId),
                    'visibility' => 'cp.visibility',
                ]);
        }
        return $this->productsSelects[$storeId];
    }

    /**
     * Products of the subcategories of every active anchor category
     *
     * @param Store $store
     * @return Select
     * @throws \Exception
     */
    protected function createAnchorSelect(Store $store)
    {
        // The core select sets the current store, private, and builds the temporary category tree of that store
        parent::createAnchorSelect($store);
        $rootCatIds = explode('/', (string)$this->getPathFromCategoryId($store->getRootCategoryId()));
        array_pop($rootCatIds);

        return $this->connection->select()
            ->from(['cc' => $this->categorySnapshot($store)], [])
            ->joinInner(
                ['cc2' => $this->getTemporaryTreeIndexTableName()],
                $this->connection->quoteInto(
                    'cc2.parent_id = cc.entity_id AND cc.entity_id NOT IN (?)',
                    $rootCatIds,
                    'INT'
                ),
                []
            )->joinInner(
                ['ccp' => $this->getTable('catalog_category_product')],
                'ccp.category_id = cc2.child_id',
                []
            )->joinLeft(
                ['ccp2' => $this->getTable('catalog_category_product')],
                'ccp2.category_id = cc2.parent_id AND ccp.product_id = ccp2.product_id',
                []
            )->joinInner(
                ['cpe' => $this->productSnapshot($store)],
                'cpe.entity_id = ccp.product_id AND cpe.children_enabled = 1',
                []
            )->where('cc.is_active = ?', 1)
            ->where('cc.is_anchor = ?', 1)
            ->columns([
                'category_id' => 'cc.entity_id',
                'product_id' => 'ccp.product_id',
                'position' => $this->connection->getIfNullSql('ccp2.position', 'MIN(ccp.position) + 10000'),
                'is_parent' => new \Zend_Db_Expr('0'),
                'store_id' => new \Zend_Db_Expr((string)(int)$store->getId()),
                'visibility' => 'cpe.visibility',
            ])->group(['cc.entity_id', 'ccp.product_id', 'visibility']);
    }

    /**
     * Products assigned to every active category of the store root
     *
     * @param Store $store
     * @return Select
     * @throws \Exception
     */
    protected function getNonAnchorCategoriesSelect(Store $store)
    {
        $storeId = (int)$store->getId();
        if (!isset($this->nonAnchorSelects[$storeId])) {
            $rootPath = $this->getPathFromCategoryId($store->getRootCategoryId());
            $this->nonAnchorSelects[$storeId] = $this->connection->select()
                ->from(['cc' => $this->getTable('catalog_category_entity')], [])
                ->joinInner(
                    ['ccs' => $this->categorySnapshot($store)],
                    'ccs.entity_id = cc.entity_id',
                    []
                )->joinInner(
                    ['ccp' => $this->getTable('catalog_category_product')],
                    'ccp.category_id = cc.entity_id',
                    []
                )->joinInner(
                    ['cpe' => $this->productSnapshot($store)],
                    'cpe.entity_id = ccp.product_id AND cpe.children_enabled = 1',
                    []
                )->where('ccs.is_active = ?', 1)
                ->where('cc.path LIKE ' . $this->connection->quote($rootPath . '/%'))
                ->columns([
                    'category_id' => 'cc.entity_id',
                    'product_id' => 'ccp.product_id',
                    'position' => 'ccp.position',
                    'is_parent' => new \Zend_Db_Expr('1'),
                    'store_id' => new \Zend_Db_Expr((string)$storeId),
                    'visibility' => 'cpe.visibility',
                ]);
        }
        return $this->nonAnchorSelects[$storeId];
    }

    /**
     * Product snapshot of the store, built on first use on the connection that reindexes it
     *
     * @param Store $store
     * @return string
     * @throws \Exception
     */
    private function productSnapshot(Store $store): string
    {
        return $this->productSnapshots[(int)$store->getId()] ??= $this->storeSnapshot->products(
            $this->connection,
            $store,
            array_map('intval', $this->productVisibility->getVisibleInSiteIds())
        );
    }

    /**
     * Category snapshot of the store, built on first use on the connection that reindexes it
     *
     * @param Store $store
     * @return string
     * @throws \Exception
     */
    private function categorySnapshot(Store $store): string
    {
        return $this->categorySnapshots[(int)$store->getId()]
            ??= $this->storeSnapshot->categories($this->connection, $store);
    }

    /**
     * Reindex the categories of one select in batches of product IDs, as the core does
     *
     * @param Select $basicSelect
     * @param string $whereCondition
     * @param Store $store
     * @return void
     * @throws \Exception
     */
    private function reindexCategoriesBySelect(Select $basicSelect, string $whereCondition, Store $store): void
    {
        $storeId = (int)$store->getId();
        $this->tableMaintainer->createMainTmpTable($storeId);

        $batchRowsCount = (int)($this->deploymentConfig->get(
            self::DEPLOYMENT_CONFIG_INDEXER_BATCHES . Product::INDEXER_ID
        ) ?? $this->batchRowsCount);
        $this->batchSizeManagement->ensureBatchSize($this->connection, $batchRowsCount);

        $entityMetadata = $this->metadataPool->getMetadata(ProductInterface::class);
        $select = $this->connection->select();
        $select->distinct(true);
        $select->from(['e' => $entityMetadata->getEntityTable()], $entityMetadata->getIdentifierField());

        $this->batchWriter->write(
            $this->connection,
            $basicSelect,
            $whereCondition,
            $this->prepareSelectsByRange($select, $entityMetadata->getIdentifierField(), $batchRowsCount),
            $this->tableMaintainer->getMainTmpTable($storeId),
            $this->tableMaintainer->getMainReplicaTable($storeId)
        );
    }
}
