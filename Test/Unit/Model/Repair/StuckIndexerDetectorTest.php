<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Repair;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Lock\LockManagerInterface;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Repair\ProcessListReader;
use Orangecat\IndexerBoost\Model\Repair\StuckIndexer;
use Orangecat\IndexerBoost\Model\Repair\StuckIndexerDetector;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StuckIndexerDetectorTest extends TestCase
{
    use WriteGuardAdapterTrait;

    /**
     * Raw indexer_state rows returned for the working query
     *
     * @var array<int,array<string,string>>
     */
    private array $working = [['indexer_id' => 'catalog_product_price', 'minutes' => '185']];

    /**
     * @var bool
     */
    private bool $applicationLock = true;

    /**
     * @var string[] lock names currently held
     */
    private array $heldLocks = [];

    /**
     * @var bool
     */
    private bool $processPrivilege = true;

    /**
     * @var string[]
     */
    private array $runningStatements = [];

    /**
     * @var string[]
     */
    private array $queries = [];

    /**
     * @var LockManagerInterface|null
     */
    private ?LockManagerInterface $lockManager = null;

    public function testWithoutApplicationLockWorkingIndexersCannotBeVerified(): void
    {
        $this->applicationLock = false;
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->never())->method('isLocked');
        $this->lockManager = $lockManager;

        $stuck = $this->detector()->detect();

        $this->assertSame(['catalog_product_price'], array_keys($stuck));
        $this->assertSame(StuckIndexer::UNVERIFIABLE, $stuck['catalog_product_price']->verdict);
        $this->assertSame(185, $stuck['catalog_product_price']->minutesWorking);
        $this->assertSame([], preg_grep('~PROCESSLIST|USER_PRIVILEGES~', $this->queries));
        $this->assertNoWriteAttempts();
    }

    public function testHeldLockMeansRunningAndFreeLockWithoutActivityMeansStuck(): void
    {
        $this->working[] = ['indexer_id' => 'catalogrule_rule', 'minutes' => '300'];
        $this->heldLocks = ['INDEXERcatalogrule_rule'];

        $stuck = $this->detector()->detect();

        $this->assertSame(StuckIndexer::STUCK, $stuck['catalog_product_price']->verdict);
        $this->assertSame(StuckIndexer::RUNNING, $stuck['catalogrule_rule']->verdict);
    }

    #[DataProvider('statementProvider')]
    public function testWritesOnTheIndexerTablesMeanActive(string $indexerId, string $statement, string $verdict): void
    {
        $this->working = [['indexer_id' => $indexerId, 'minutes' => '185']];
        $this->runningStatements = [$statement];

        $stuck = $this->detector()->detect()[$indexerId];

        $this->assertSame($verdict, $stuck->verdict);
        $this->assertSame($verdict === StuckIndexer::ACTIVE ? $statement : null, $stuck->statement);
    }

    /**
     * @return array<string,array{string,string,string}>
     */
    public static function statementProvider(): array
    {
        return [
            'mapped table' => [
                'catalog_product_price',
                'INSERT INTO pfx_catalog_product_index_price_replica SELECT 1',
                StuckIndexer::ACTIVE,
            ],
            'second mapped table, any case' => [
                'catalogrule_rule',
                'DELETE FROM PFX_CATALOGRULE_GROUP_WEBSITE',
                StuckIndexer::ACTIVE,
            ],
            'unrelated table' => [
                'catalog_product_price',
                'UPDATE pfx_sales_order SET status = 1',
                StuckIndexer::STUCK,
            ],
            'table without the prefix' => [
                'catalog_product_price',
                'INSERT INTO catalog_product_index_price SELECT 1',
                StuckIndexer::STUCK,
            ],
            'unmapped indexer uses its id' => [
                'customer_grid',
                'INSERT INTO pfx_customer_grid_flat_replica SELECT 1',
                StuckIndexer::ACTIVE,
            ],
        ];
    }

    public function testWithoutProcessPrivilegeAFreeLockIsDoubtful(): void
    {
        $this->processPrivilege = false;

        $this->assertSame(StuckIndexer::DOUBTFUL, $this->detector()->detect()['catalog_product_price']->verdict);
    }

    public function testWithoutProcessPrivilegeOwnVisibleWritesStillMeanActive(): void
    {
        $this->processPrivilege = false;
        $this->runningStatements = ['INSERT INTO pfx_catalog_product_index_price_replica SELECT 1'];

        $this->assertSame(StuckIndexer::ACTIVE, $this->detector()->detect()['catalog_product_price']->verdict);
    }

    public function testOnlyIndexersWorkingLongerThanTheThresholdAreQueried(): void
    {
        $binds = [];
        $connection = $this->createWriteGuardAdapter(
            static function (string $sql, string $method, array $bind) use (&$binds): array {
                $binds[] = $bind;
                return [];
            }
        );
        $detector = new StuckIndexerDetector(
            new ReadOnlyConnection($this->createResourceConnection($connection)),
            $this->createStub(LockManagerInterface::class),
            $this->createStub(DeploymentConfig::class),
            $this->createStub(ProcessListReader::class),
            [],
            120
        );

        $this->assertSame([], $detector->detect());
        $this->assertSame([[120]], $binds);
    }

    public function testWorkingIndexerIdsComeFromTheRawStatus(): void
    {
        $queries = [];
        $connection = $this->createWriteGuardAdapter(
            static function (string $sql, string $method) use (&$queries): array {
                $queries[] = [$method, $sql];
                return ['catalog_product_price', 'inventory'];
            }
        );
        $detector = new StuckIndexerDetector(
            new ReadOnlyConnection($this->createResourceConnection($connection)),
            $this->createStub(LockManagerInterface::class),
            $this->createStub(DeploymentConfig::class),
            $this->createStub(ProcessListReader::class)
        );

        $this->assertSame(['catalog_product_price', 'inventory'], $detector->getWorkingIndexerIds());
        $this->assertSame(
            [['fetchCol', "SELECT indexer_id FROM pfx_indexer_state WHERE status = 'working'"]],
            $queries
        );
    }

    /**
     * Detector over the fake state of this test
     *
     * @return StuckIndexerDetector
     */
    private function detector(): StuckIndexerDetector
    {
        $connection = new ReadOnlyConnection($this->createResourceConnection($this->createWriteGuardAdapter(
            function (string $sql, string $method, array $bind): mixed {
                $this->queries[] = $sql;
                return match (true) {
                    str_contains($sql, 'pfx_indexer_state') => $this->working,
                    str_contains($sql, 'USER_PRIVILEGES') => $this->processPrivilege ? '1' : '0',
                    str_contains($sql, 'PROCESSLIST') => $this->runningStatements,
                    default => throw new \LogicException($sql),
                };
            }
        )));
        $deploymentConfig = $this->createStub(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnMap([
            ['indexer/use_application_lock', null, $this->applicationLock],
            ['indexer/use_application_lock', false, $this->applicationLock],
        ]);
        if ($this->lockManager === null) {
            $lockManager = $this->createStub(LockManagerInterface::class);
            $lockManager->method('isLocked')->willReturnCallback(
                fn (string $name): bool => in_array($name, $this->heldLocks, true)
            );
            $this->lockManager = $lockManager;
        }

        return new StuckIndexerDetector(
            $connection,
            $this->lockManager,
            $deploymentConfig,
            new ProcessListReader($connection),
            [
                'catalog_product_price' => ['catalog_product_index_price'],
                'catalogrule_rule' => ['catalogrule_product', 'catalogrule_group_website'],
            ],
            120
        );
    }
}
