<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Repair;

use Magento\Framework\Indexer\ConfigInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerInterfaceFactory;
use Magento\Indexer\Model\Processor\MakeSharedIndexValid;
use Magento\Framework\Exception\LocalizedException;
use Orangecat\IndexerBoost\Model\Monitor\Clock;
use Orangecat\IndexerBoost\Model\Repair\ReindexOutcome;
use Orangecat\IndexerBoost\Model\Repair\Repairer;
use Orangecat\IndexerBoost\Model\Repair\StuckIndexer;
use Orangecat\IndexerBoost\Model\Repair\StuckIndexerDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RepairerTest extends TestCase
{
    /**
     * Indexer config in dependency order: indexer id => config
     *
     * @var array<string,array<string,mixed>>
     */
    private array $config = [];

    /**
     * @var array<string,string> indexer id => status
     */
    private array $statuses = [];

    /**
     * @var array<string,string> indexer id => message of the exception its full reindex throws
     */
    private array $failures = [];

    /**
     * @var string[] shared indexes made valid, in order
     */
    private array $sharedMadeValid = [];

    /**
     * @var string[] indexer ids whose raw indexer_state.status is "working"
     */
    private array $rawWorking = [];

    /**
     * @var string[] indexer ids in the order their full reindex ran
     */
    private array $reindexed = [];

    public function testIndexerWhoseLockIsHeldIsNeverInvalidated(): void
    {
        $detector = $this->createStub(StuckIndexerDetector::class);
        $detector->method('detect')->willReturn([
            'catalog_product_price' => new StuckIndexer('catalog_product_price', 185, StuckIndexer::RUNNING),
        ]);
        $factory = $this->createMock(IndexerInterfaceFactory::class);
        $factory->expects($this->never())->method('create');

        $repairer = new Repairer(
            $detector,
            $factory,
            $this->createStub(ConfigInterface::class),
            $this->createStub(MakeSharedIndexValid::class),
            $this->createStub(Clock::class),
            'cli'
        );

        $this->assertFalse($repairer->invalidate('catalog_product_price'));
    }

    #[DataProvider('releasableProvider')]
    public function testStuckOrDoubtfulIndexerIsInvalidated(string $verdict): void
    {
        $detector = $this->createStub(StuckIndexerDetector::class);
        $detector->method('detect')->willReturn([
            'catalog_product_price' => new StuckIndexer('catalog_product_price', 185, $verdict),
        ]);
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects($this->once())->method('load')->with('catalog_product_price')->willReturnSelf();
        $indexer->expects($this->once())->method('invalidate');
        $factory = $this->createStub(IndexerInterfaceFactory::class);
        $factory->method('create')->willReturn($indexer);

        $repairer = new Repairer(
            $detector,
            $factory,
            $this->createStub(ConfigInterface::class),
            $this->createStub(MakeSharedIndexValid::class),
            $this->createStub(Clock::class),
            'cli'
        );

        $this->assertTrue($repairer->invalidate('catalog_product_price'));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function releasableProvider(): array
    {
        return ['stuck' => [StuckIndexer::STUCK], 'doubtful' => [StuckIndexer::DOUBTFUL]];
    }

    public function testFindStuckReturnsTheDetectorVerdicts(): void
    {
        $stuck = ['inventory' => new StuckIndexer('inventory', 200, StuckIndexer::DOUBTFUL)];
        $detector = $this->createStub(StuckIndexerDetector::class);
        $detector->method('detect')->willReturn($stuck);

        $repairer = new Repairer(
            $detector,
            $this->createStub(IndexerInterfaceFactory::class),
            $this->createStub(ConfigInterface::class),
            $this->createStub(MakeSharedIndexValid::class),
            $this->createStub(Clock::class),
            'cli'
        );

        $this->assertSame($stuck, $repairer->findStuck());
    }

    #[DataProvider('operationProvider')]
    public function testRefusesToRunOutsideTheCommandLine(string $operation): void
    {
        $detector = $this->createMock(StuckIndexerDetector::class);
        $detector->expects($this->never())->method('detect');
        $factory = $this->createMock(IndexerInterfaceFactory::class);
        $factory->expects($this->never())->method('create');

        $repairer = new Repairer(
            $detector,
            $factory,
            $this->createStub(ConfigInterface::class),
            $this->createStub(MakeSharedIndexValid::class),
            $this->createStub(Clock::class),
            'fpm-fcgi'
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Indexer repair only runs from the command line');
        match ($operation) {
            'findStuck' => $repairer->findStuck(),
            'invalidate' => $repairer->invalidate('catalog_product_price'),
            'reindexInvalid' => $repairer->reindexInvalid(),
            default => throw new \LogicException($operation),
        };
    }

    /**
     * @return array<string,array{string}>
     */
    public static function operationProvider(): array
    {
        return [
            'findStuck' => ['findStuck'],
            'invalidate' => ['invalidate'],
            'reindexInvalid' => ['reindexInvalid'],
        ];
    }

    public function testInvalidIndexersAreReindexedInConfigOrderAndTimed(): void
    {
        $this->config = ['design_config_grid' => [], 'catalog_product_price' => [], 'catalogrule_rule' => []];
        $this->statuses = [
            'design_config_grid' => 'invalid',
            'catalog_product_price' => 'valid',
            'catalogrule_rule' => 'invalid',
        ];
        $clock = $this->createStub(Clock::class);
        $clock->method('monotonic')->willReturnOnConsecutiveCalls(10.0, 12.5, 20.0, 20.25);

        $outcomes = $this->repairer($clock)->reindexInvalid();

        $this->assertSame(['design_config_grid', 'catalogrule_rule'], $this->reindexed);
        $this->assertSame(
            [['design_config_grid', 2.5, null], ['catalogrule_rule', 0.25, null]],
            array_map(static fn (ReindexOutcome $o): array => [$o->indexerId, $o->seconds, $o->error], $outcomes)
        );
    }

    public function testAFailedReindexIsReportedAndTheNextIndexersStillRun(): void
    {
        $this->config = ['catalog_product_price' => [], 'catalogrule_rule' => []];
        $this->statuses = ['catalog_product_price' => 'invalid', 'catalogrule_rule' => 'invalid'];
        $this->failures = ['catalog_product_price' => 'Deadlock found'];

        $outcomes = $this->repairer()->reindexInvalid();

        $this->assertSame(['catalog_product_price', 'catalogrule_rule'], $this->reindexed);
        $this->assertSame(['Deadlock found', null], array_map(static fn ($o): ?string => $o->error, $outcomes));
    }

    public function testIndexersSharingAnIndexAreRebuiltOnce(): void
    {
        $this->config = [
            'catalogrule_rule' => ['shared_index' => 'catalogrule'],
            'catalogrule_product' => ['shared_index' => 'catalogrule'],
        ];
        $this->statuses = ['catalogrule_rule' => 'invalid', 'catalogrule_product' => 'invalid'];

        $outcomes = $this->repairer()->reindexInvalid();

        $this->assertSame(['catalogrule_rule'], $this->reindexed);
        $this->assertSame(['catalogrule'], $this->sharedMadeValid);
        $this->assertCount(1, $outcomes);
    }

    public function testInvalidIndexerIsSkippedWhileAnotherIndexerOfItsSharedIndexIsSuspended(): void
    {
        $this->config = [
            'design_config_grid' => [],
            'catalogrule_rule' => ['shared_index' => 'catalogrule'],
            'catalogrule_product' => ['shared_index' => 'catalogrule'],
        ];
        $this->statuses = [
            'design_config_grid' => 'invalid',
            'catalogrule_rule' => 'invalid',
            'catalogrule_product' => 'suspended',
        ];

        $this->repairer()->reindexInvalid();

        $this->assertSame(['design_config_grid'], $this->reindexed);
    }

    public function testIndexerSeenInvalidOnlyBecauseItsLockIsFreeIsNotReindexed(): void
    {
        // With the application lock the core reports "working" without lock as invalid: the repair left it alone
        $this->config = ['catalog_product_price' => [], 'inventory' => []];
        $this->statuses = ['catalog_product_price' => 'invalid', 'inventory' => 'invalid'];
        $this->rawWorking = ['inventory'];

        $this->repairer()->reindexInvalid();

        $this->assertSame(['catalog_product_price'], $this->reindexed);
    }

    /**
     * Repairer over the fake indexers of this test
     *
     * @param Clock|null $clock
     * @return Repairer
     */
    private function repairer(?Clock $clock = null): Repairer
    {
        $config = $this->createStub(ConfigInterface::class);
        $config->method('getIndexers')->willReturnCallback(fn (): array => $this->config);
        $factory = $this->createStub(IndexerInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(function (): IndexerInterface {
            $loader = $this->createStub(IndexerInterface::class);
            $loader->method('load')->willReturnCallback(fn (string $id): IndexerInterface => $this->indexer($id));
            return $loader;
        });

        $makeSharedValid = $this->createStub(MakeSharedIndexValid::class);
        $makeSharedValid->method('execute')->willReturnCallback(function (string $shared): bool {
            $this->sharedMadeValid[] = $shared;
            foreach ($this->config as $id => $config) {
                if (($config['shared_index'] ?? null) === $shared) {
                    $this->statuses[$id] = 'valid';
                }
            }
            return true;
        });

        $detector = $this->createStub(StuckIndexerDetector::class);
        $detector->method('getWorkingIndexerIds')->willReturnCallback(fn (): array => $this->rawWorking);

        return new Repairer(
            $detector,
            $factory,
            $config,
            $makeSharedValid,
            $clock ?? $this->createStub(Clock::class),
            'cli'
        );
    }

    /**
     * Fake indexer whose status and full reindex use the state of this test
     *
     * @param string $id
     * @return IndexerInterface
     */
    private function indexer(string $id): IndexerInterface
    {
        $indexer = $this->createStub(IndexerInterface::class);
        $indexer->method('getId')->willReturn($id);
        $indexer->method('getStatus')->willReturnCallback(fn (): string => $this->statuses[$id]);
        $indexer->method('isInvalid')->willReturnCallback(fn (): bool => $this->statuses[$id] === 'invalid');
        $indexer->method('isValid')->willReturnCallback(fn (): bool => $this->statuses[$id] === 'valid');
        $indexer->method('reindexAll')->willReturnCallback(function () use ($id): void {
            $this->reindexed[] = $id;
            if (isset($this->failures[$id])) {
                throw new \RuntimeException($this->failures[$id]);
            }
            $this->statuses[$id] = 'valid';
        });
        return $indexer;
    }
}
