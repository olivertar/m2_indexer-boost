<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Cron;

use Orangecat\IndexerBoost\Cron\CleanupRunLog;
use Orangecat\IndexerBoost\Model\Config;
use Orangecat\IndexerBoost\Model\Monitor\Clock;
use Orangecat\IndexerBoost\Model\ResourceModel\RunLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanupRunLogTest extends TestCase
{
    /**
     * @var RunLog
     */
    private RunLog $runLog;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->runLog = $this->createStub(RunLog::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    public function testDeletesRunsOlderThanTheRetention(): void
    {
        $runLog = $this->createMock(RunLog::class);
        $runLog->expects($this->once())
            ->method('deleteOlderThan')
            ->with($this->equalTo(new \DateTimeImmutable('2026-08-02 08:00:00', new \DateTimeZone('UTC'))))
            ->willReturn(0);
        $this->runLog = $runLog;

        $this->cron(90)->execute();
    }

    #[DataProvider('deletedProvider')]
    public function testLogsHowManyRunsWereDeleted(int $deleted, bool $logged): void
    {
        $runLog = $this->createStub(RunLog::class);
        $runLog->method('deleteOlderThan')->willReturn($deleted);
        $this->runLog = $runLog;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($logged ? $this->once() : $this->never())
            ->method('info')
            ->with('Indexer Boost: deleted 1250 run log rows older than 30 days');
        $this->logger = $logger;

        $this->cron(30)->execute();
    }

    /**
     * @return array<string,array{int,bool}>
     */
    public static function deletedProvider(): array
    {
        return [
            'rows deleted' => [1250, true],
            'nothing to delete' => [0, false],
        ];
    }

    public function testDatabaseFailureIsLoggedAndNotThrown(): void
    {
        $runLog = $this->createStub(RunLog::class);
        $runLog->method('deleteOlderThan')->willThrowException(new \RuntimeException('Lock wait timeout exceeded'));
        $this->runLog = $runLog;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Indexer Boost: run log cleanup failed: Lock wait timeout exceeded');
        $this->logger = $logger;

        $this->cron(90)->execute();
    }

    /**
     * @param int $retentionDays
     * @return CleanupRunLog
     */
    private function cron(int $retentionDays): CleanupRunLog
    {
        $config = $this->createStub(Config::class);
        $config->method('getRetentionDays')->willReturn($retentionDays);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-10-31 08:00:00', new \DateTimeZone('UTC')));

        return new CleanupRunLog($this->runLog, $config, $clock, $this->logger);
    }
}
