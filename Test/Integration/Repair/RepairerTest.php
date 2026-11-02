<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration\Repair;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Indexer\Model\Indexer\State;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBoost\Model\Repair\ReindexOutcome;
use Orangecat\IndexerBoost\Model\Repair\Repairer;
use Orangecat\IndexerBoost\Model\Repair\StuckIndexerDetector;
use Orangecat\IndexerBoost\Test\Integration\Fixture\ApplicationLockDeploymentConfig;
use Orangecat\IndexerBoost\Test\Integration\Fixture\DatabaseLock;
use PHPUnit\Framework\TestCase;

/**
 * Release a stuck indexer and rebuild it on the real database (a full reindex commits, so no transaction isolation)
 */
#[DbIsolation(false)]
class RepairerTest extends TestCase
{
    private const INDEXER_ID = 'design_config_grid';

    /**
     * @var AdapterInterface
     */
    private AdapterInterface $connection;

    protected function setUp(): void
    {
        $this->connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $this->releaseLocks();
        // The core State model must see the application lock and the real lock backend
        // (ApplicationLockDeploymentConfig, DatabaseLock): it then reports "working" without lock as invalid
        Bootstrap::getObjectManager()->configure([
            State::class => ['arguments' => [
                'configReader' => ['instance' => ApplicationLockDeploymentConfig::class],
                'lockManager' => ['instance' => DatabaseLock::class],
            ]],
        ]);
    }

    protected function tearDown(): void
    {
        Bootstrap::getObjectManager()->configure([
            State::class => ['arguments' => [
                'configReader' => ['instance' => DeploymentConfig::class],
                'lockManager' => ['instance' => LockManagerInterface::class],
            ]],
        ]);
        $this->setStatus('valid', 'UTC_TIMESTAMP()');
        $this->releaseLocks();
    }

    /**
     * Release every lock of the test connection
     *
     * In one process Indexer::reindexAll() takes the INDEXER lock twice (GET_LOCK nests) and releases it once, so the
     * lock outlives the reindex until the connection closes; in production the CLI process ends there.
     *
     * @return void
     */
    private function releaseLocks(): void
    {
        $this->connection->query('SELECT RELEASE_ALL_LOCKS()');
    }

    public function testStuckIndexerIsReleasedAndRebuilt(): void
    {
        $this->setStatus('working', 'UTC_TIMESTAMP() - INTERVAL 3 HOUR');
        $repairer = $this->repairer();

        $this->assertTrue($repairer->invalidate(self::INDEXER_ID));
        $this->assertSame('invalid', $this->rawStatus());

        $outcomes = array_filter(
            $repairer->reindexInvalid(),
            static fn (ReindexOutcome $outcome): bool => $outcome->indexerId === self::INDEXER_ID
        );
        $this->assertCount(1, $outcomes);
        $outcome = reset($outcomes);
        $this->assertInstanceOf(ReindexOutcome::class, $outcome);
        $this->assertNull($outcome->error);
        $this->assertSame('valid', $this->rawStatus());
    }

    public function testIndexerStillWorkingIsNotRebuiltEvenIfTheCoreSeesItInvalid(): void
    {
        // Recently working: not a stuck candidate, but with the lock free the core reports it as invalid
        $this->setStatus('working', 'UTC_TIMESTAMP()');
        $this->assertTrue(
            Bootstrap::getObjectManager()->create(IndexerRegistry::class)->get(self::INDEXER_ID)->isInvalid(),
            'The core should report a working indexer without lock as invalid'
        );

        $rebuilt = array_map(
            static fn (ReindexOutcome $outcome): string => $outcome->indexerId,
            $this->repairer()->reindexInvalid()
        );

        $this->assertNotContains(self::INDEXER_ID, $rebuilt);
        $this->assertSame('working', $this->rawStatus());
    }

    /**
     * Repairer whose detector sees the application lock enabled
     *
     * @return Repairer
     */
    private function repairer(): Repairer
    {
        $detector = Bootstrap::getObjectManager()->create(
            StuckIndexerDetector::class,
            [
                'deploymentConfig' => Bootstrap::getObjectManager()->get(ApplicationLockDeploymentConfig::class),
                'lockManager' => Bootstrap::getObjectManager()->get(DatabaseLock::class),
            ]
        );
        return Bootstrap::getObjectManager()->create(Repairer::class, ['detector' => $detector]);
    }

    /**
     * Set the raw status of the test indexer
     *
     * @param string $status
     * @param string $updatedSql
     * @return void
     */
    private function setStatus(string $status, string $updatedSql): void
    {
        $this->connection->update(
            $this->connection->getTableName('indexer_state'),
            ['status' => $status, 'updated' => new \Zend_Db_Expr($updatedSql)],
            ['indexer_id = ?' => self::INDEXER_ID]
        );
    }

    /**
     * Raw status of the test indexer
     *
     * @return string
     */
    private function rawStatus(): string
    {
        return (string)$this->connection->fetchOne(
            'SELECT status FROM ' . $this->connection->getTableName('indexer_state') . ' WHERE indexer_id = ?',
            [self::INDEXER_ID]
        );
    }
}
