<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration\Repair;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBoost\Model\Repair\ProcessListReader;
use Orangecat\IndexerBoost\Model\Repair\StuckIndexer;
use Orangecat\IndexerBoost\Model\Repair\StuckIndexerDetector;
use Orangecat\IndexerBoost\Test\Integration\Fixture\DatabaseLock;
use PHPUnit\Framework\TestCase;

/**
 * Verdicts on the real indexer_state table, lock backend and information_schema
 */
#[DbIsolation(true)]
class StuckIndexerDetectorTest extends TestCase
{
    private const INDEXER_ID = 'design_config_grid';

    private const LOCK = 'INDEXER' . self::INDEXER_ID;

    protected function setUp(): void
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $connection->update(
            $connection->getTableName('indexer_state'),
            ['status' => 'working', 'updated' => new \Zend_Db_Expr('UTC_TIMESTAMP() - INTERVAL 3 HOUR')],
            ['indexer_id = ?' => self::INDEXER_ID]
        );
    }

    protected function tearDown(): void
    {
        Bootstrap::getObjectManager()->get(DatabaseLock::class)->unlock(self::LOCK);
    }

    public function testWithoutTheApplicationLockAWorkingIndexerCannotBeVerified(): void
    {
        $stuck = $this->detector(false)->detect()[self::INDEXER_ID] ?? null;

        $this->assertNotNull($stuck);
        $this->assertSame(StuckIndexer::UNVERIFIABLE, $stuck->verdict);
        $this->assertGreaterThanOrEqual(179, $stuck->minutesWorking);
    }

    public function testHeldLockMeansRunningAndAFreeLockDependsOnThePrivilege(): void
    {
        $lockManager = Bootstrap::getObjectManager()->get(DatabaseLock::class);
        $this->assertTrue($lockManager->lock(self::LOCK, 0));

        $this->assertSame(StuckIndexer::RUNNING, $this->detector(true)->detect()[self::INDEXER_ID]->verdict);

        $lockManager->unlock(self::LOCK);
        $privilege = Bootstrap::getObjectManager()->create(ProcessListReader::class)->hasProcessPrivilege();
        $this->assertSame(
            $privilege ? StuckIndexer::STUCK : StuckIndexer::DOUBTFUL,
            $this->detector(true)->detect()[self::INDEXER_ID]->verdict
        );
    }

    public function testProcessPrivilegeMatchesTheGrantsOfTheCurrentUser(): void
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $grants = implode("\n", $connection->fetchCol('SHOW GRANTS'));
        $expected = (bool)preg_match('~GRANT (?:ALL PRIVILEGES|[^\n]*\bPROCESS\b[^\n]*) ON \*\.\*~', $grants);

        $this->assertSame(
            $expected,
            Bootstrap::getObjectManager()->create(ProcessListReader::class)->hasProcessPrivilege()
        );
    }

    /**
     * Detector with the application lock switched on or off
     *
     * @param bool $applicationLock
     * @return StuckIndexerDetector
     */
    private function detector(bool $applicationLock): StuckIndexerDetector
    {
        $deploymentConfig = $this->createStub(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed =>
                $key === 'indexer/use_application_lock' ? $applicationLock : $default
        );
        return Bootstrap::getObjectManager()->create(
            StuckIndexerDetector::class,
            [
                'deploymentConfig' => $deploymentConfig,
                'lockManager' => Bootstrap::getObjectManager()->get(DatabaseLock::class),
            ]
        );
    }
}
