<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration\Monitor;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Indexer\Model\Indexer\DependencyDecorator;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBoost\Model\ResourceModel\RunLog;
use PHPUnit\Framework\TestCase;

/**
 * A full reindex through the real DI (IndexerInterface -> DependencyDecorator -> Indexer) is recorded once
 */
#[DbIsolation(false)]
class FullReindexRecordedOnceTest extends TestCase
{
    private const INDEXER_ID = 'design_config_grid';

    public function testFullReindexThroughTheDependencyDecoratorIsRecordedOnce(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $connection = $objectManager->get(ResourceConnection::class)->getConnection();
        $table = $connection->getTableName(RunLog::TABLE);
        $lastRunId = (int)$connection->fetchOne("SELECT COALESCE(MAX(run_id), 0) FROM {$table}");
        $indexer = $objectManager->create(IndexerRegistry::class)->get(self::INDEXER_ID);

        $indexer->reindexAll();

        $this->assertInstanceOf(DependencyDecorator::class, $indexer);
        $this->assertSame(
            [['indexer_id' => self::INDEXER_ID, 'kind' => 'full', 'failed' => '0']],
            $connection->fetchAll(
                "SELECT indexer_id, kind, failed FROM {$table} WHERE run_id > ? ORDER BY run_id",
                [$lastRunId]
            )
        );
    }
}
