<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Monitor;

use Orangecat\IndexerBoost\Model\Config;
use Orangecat\IndexerBoost\Model\Monitor\RunRecorder;
use Orangecat\IndexerBoost\Model\ResourceModel\RunLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RunRecorderTest extends TestCase
{
    /**
     * @var RunLog
     */
    private RunLog $runLog;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var \DateTimeImmutable
     */
    private \DateTimeImmutable $startedAt;

    protected function setUp(): void
    {
        $this->runLog = $this->createStub(RunLog::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->startedAt = new \DateTimeImmutable('2026-10-31 08:00:00', new \DateTimeZone('UTC'));
    }

    public function testFullRunIsSaved(): void
    {
        $this->runLog = $this->mockRunLog();
        $this->runLog->expects($this->once())
            ->method('saveRun')
            ->with('catalog_product_price', 'full', $this->startedAt, 0.2, null);

        $this->recorder()->record('catalog_product_price', RunRecorder::KIND_FULL, $this->startedAt, 0.2);
    }

    #[DataProvider('changesProvider')]
    public function testChangesRunsBelowOneSecondAreSkippedUnlessFailed(float $seconds, bool $failed, bool $saved): void
    {
        $this->runLog = $this->mockRunLog();
        $this->runLog->expects($saved ? $this->once() : $this->never())->method('saveRun');

        $this->recorder()->record(
            'catalogsearch_fulltext',
            RunRecorder::KIND_CHANGES,
            $this->startedAt,
            $seconds,
            $failed ? new \RuntimeException('boom') : null
        );
    }

    /**
     * @return array<string,array{float,bool,bool}>
     */
    public static function changesProvider(): array
    {
        return [
            'fast success' => [0.999, false, false],
            'one second' => [1.0, false, true],
            'fast failure' => [0.01, true, true],
        ];
    }

    public function testErrorIsStoredAsClassAndTruncatedMessage(): void
    {
        $this->runLog = $this->mockRunLog();
        $this->runLog->expects($this->once())
            ->method('saveRun')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(static fn (string $error): bool => mb_strlen($error) === 2000
                    && str_starts_with($error, 'RuntimeException: xxx'))
            );

        $this->recorder()->record(
            'catalog_product_price',
            RunRecorder::KIND_FULL,
            $this->startedAt,
            3.0,
            new \RuntimeException(str_repeat('x', 5000))
        );
    }

    public function testDisabledMonitorSavesNothing(): void
    {
        $this->runLog = $this->mockRunLog();
        $this->runLog->expects($this->never())->method('saveRun');

        $this->recorder(false)->record('catalog_product_price', RunRecorder::KIND_FULL, $this->startedAt, 10.0);
    }

    public function testSaveFailureIsLoggedAndNeverPropagated(): void
    {
        $runLog = $this->createStub(RunLog::class);
        $runLog->method('saveRun')->willThrowException(new \RuntimeException('Table does not exist'));
        $this->runLog = $runLog;
        $this->logger = $this->mockLogger();
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Indexer Boost: could not record the indexer run: Table does not exist',
                $this->callback(static fn (array $context): bool => $context['indexer_id'] === 'catalog_product_price')
            );

        $this->recorder()->record('catalog_product_price', RunRecorder::KIND_FULL, $this->startedAt, 10.0);
    }

    public function testConfigFailureIsNeverPropagated(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isMonitorEnabled')->willThrowException(new \LogicException('Config not loaded'));
        $this->logger = $this->mockLogger();
        $this->logger->expects($this->once())->method('warning');

        (new RunRecorder($this->runLog, $config, $this->logger))
            ->record('catalog_product_price', RunRecorder::KIND_FULL, $this->startedAt, 10.0);
    }

    /**
     * @return RunLog&MockObject
     */
    private function mockRunLog(): RunLog
    {
        return $this->createMock(RunLog::class);
    }

    /**
     * @return LoggerInterface&MockObject
     */
    private function mockLogger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    /**
     * @param bool $enabled
     * @return RunRecorder
     */
    private function recorder(bool $enabled = true): RunRecorder
    {
        $config = $this->createStub(Config::class);
        $config->method('isMonitorEnabled')->willReturn($enabled);
        return new RunRecorder($this->runLog, $config, $this->logger, 1.0);
    }
}
