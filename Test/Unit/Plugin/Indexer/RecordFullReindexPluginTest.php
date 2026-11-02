<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Plugin\Indexer;

use Magento\Indexer\Model\Indexer;
use Magento\Indexer\Model\WorkingStateProvider;
use Orangecat\IndexerBoost\Model\Config;
use Orangecat\IndexerBoost\Model\Monitor\Clock;
use Orangecat\IndexerBoost\Model\Monitor\RunRecorder;
use Orangecat\IndexerBoost\Model\ResourceModel\RunLog;
use Orangecat\IndexerBoost\Plugin\Indexer\RecordFullReindexPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RecordFullReindexPluginTest extends TestCase
{
    /**
     * @var \DateTimeImmutable
     */
    private \DateTimeImmutable $startedAt;

    /**
     * @var WorkingStateProvider
     */
    private WorkingStateProvider $workingState;

    /**
     * @var RunRecorder
     */
    private RunRecorder $recorder;

    protected function setUp(): void
    {
        $this->startedAt = new \DateTimeImmutable('2026-10-31 08:00:00', new \DateTimeZone('UTC'));
        $this->workingState = $this->createStub(WorkingStateProvider::class);
        $this->recorder = $this->createStub(RunRecorder::class);
    }

    public function testRecordsFullRunWithStartAndDuration(): void
    {
        $recorder = $this->createMock(RunRecorder::class);
        $recorder->expects($this->once())
            ->method('record')
            ->with('catalog_product_price', RunRecorder::KIND_FULL, $this->startedAt, 12.5, null);
        $this->recorder = $recorder;
        $calls = 0;

        $this->plugin()->aroundReindexAll(
            $this->indexer('catalog_product_price'),
            static function () use (&$calls): void {
                $calls++;
            }
        );

        $this->assertSame(1, $calls);
    }

    public function testFailedReindexIsRecordedAndOriginalExceptionRethrown(): void
    {
        $error = new \RuntimeException('Deadlock found when trying to get lock');
        $recorder = $this->createMock(RunRecorder::class);
        $recorder->expects($this->once())
            ->method('record')
            ->with('catalogrule_rule', RunRecorder::KIND_FULL, $this->startedAt, 12.5, $this->identicalTo($error));
        $this->recorder = $recorder;

        try {
            $this->plugin()->aroundReindexAll(
                $this->indexer('catalogrule_rule'),
                static function () use ($error): void {
                    throw $error;
                }
            );
            $this->fail('The reindex exception must be rethrown');
        } catch (\RuntimeException $caught) {
            $this->assertSame($error, $caught);
        }
    }

    public function testIndexerAlreadyWorkingIsNotRecorded(): void
    {
        $workingState = $this->createMock(WorkingStateProvider::class);
        $workingState->expects($this->once())->method('isWorking')->with('catalog_product_price')->willReturn(true);
        $this->workingState = $workingState;
        $recorder = $this->createMock(RunRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->recorder = $recorder;
        $calls = 0;

        $this->plugin()->aroundReindexAll(
            $this->indexer('catalog_product_price'),
            static function () use (&$calls): void {
                $calls++;
            }
        );

        $this->assertSame(1, $calls);
    }

    public function testWorkingStateLookupFailureDoesNotBlockTheReindex(): void
    {
        $workingState = $this->createStub(WorkingStateProvider::class);
        $workingState->method('isWorking')->willThrowException(new \RuntimeException('MySQL server has gone away'));
        $this->workingState = $workingState;
        $recorder = $this->createMock(RunRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->recorder = $recorder;
        $calls = 0;

        $this->plugin()->aroundReindexAll(
            $this->indexer('catalog_product_price'),
            static function () use (&$calls): void {
                $calls++;
            }
        );

        $this->assertSame(1, $calls);
    }

    public function testRunLogSaveFailureDoesNotBreakTheReindex(): void
    {
        $runLog = $this->createStub(RunLog::class);
        $runLog->method('saveRun')->willThrowException(new \RuntimeException('Table is read only'));
        $config = $this->createStub(Config::class);
        $config->method('isMonitorEnabled')->willReturn(true);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $this->recorder = new RunRecorder($runLog, $config, $logger);
        $calls = 0;

        $this->plugin()->aroundReindexAll(
            $this->indexer('catalog_product_price'),
            static function () use (&$calls): void {
                $calls++;
            }
        );

        $this->assertSame(1, $calls);
    }

    /**
     * @param string $indexerId
     * @return Indexer
     */
    private function indexer(string $indexerId): Indexer
    {
        $indexer = $this->createStub(Indexer::class);
        $indexer->method('getId')->willReturn($indexerId);
        return $indexer;
    }

    /**
     * Plugin whose clock starts at $this->startedAt and measures 12.5 seconds
     *
     * @return RecordFullReindexPlugin
     */
    private function plugin(): RecordFullReindexPlugin
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->startedAt);
        $clock->method('monotonic')->willReturnOnConsecutiveCalls(100.0, 112.5);

        return new RecordFullReindexPlugin($this->workingState, $this->recorder, $clock);
    }
}
