<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration\Monitor;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use Orangecat\IndexerBoost\Model\Monitor\RunRecorder;
use Orangecat\IndexerBoost\Model\ResourceModel\RunLog;
use PHPUnit\Framework\TestCase;

/**
 * A real changelog run of a scheduled indexer is recorded under the indexer id, not the view id
 */
#[DbIsolation(false)]
class ViewUpdateRecordedTest extends TestCase
{
    private const INDEXER_ID = 'customer_grid';

    /**
     * @var ObjectManager
     */
    private ObjectManager $objectManager;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->assertInstanceOf(ObjectManager::class, $objectManager);
        $this->objectManager = $objectManager;
        // Store every successful changelog run, a test run takes milliseconds. The mview plugin gets its recorder when
        // it is first used, so the shared recorder is dropped for it to pick up this configuration.
        $this->objectManager->configure([RunRecorder::class => ['arguments' => ['minChangesSeconds' => 0.0]]]);
        $this->objectManager->removeSharedInstance(RunRecorder::class);
        $this->objectManager->get(IndexerRegistry::class)->get(self::INDEXER_ID)->setScheduled(true);
    }

    protected function tearDown(): void
    {
        $this->objectManager->get(IndexerRegistry::class)->get(self::INDEXER_ID)->setScheduled(false);
        $this->objectManager->configure([RunRecorder::class => ['arguments' => ['minChangesSeconds' => 1.0]]]);
        $this->objectManager->removeSharedInstance(RunRecorder::class);
    }

    public function testChangelogRunOfAScheduledIndexerIsRecorded(): void
    {
        $connection = $this->objectManager->get(ResourceConnection::class)->getConnection();
        $table = $connection->getTableName(RunLog::TABLE);
        $lastRunId = (int)$connection->fetchOne("SELECT COALESCE(MAX(run_id), 0) FROM {$table}");
        $view = $this->objectManager->get(IndexerRegistry::class)->get(self::INDEXER_ID)->getView();
        $connection->insert($view->getChangelog()->getName(), ['entity_id' => 1]);

        $view->update();

        $this->assertSame(
            [['indexer_id' => self::INDEXER_ID, 'kind' => 'changes', 'failed' => '0']],
            $connection->fetchAll(
                "SELECT indexer_id, kind, failed FROM {$table} WHERE run_id > ? ORDER BY run_id",
                [$lastRunId]
            )
        );
    }
}
