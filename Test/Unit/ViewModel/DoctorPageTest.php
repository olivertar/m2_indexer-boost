<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\ViewModel;

use Magento\Framework\Exception\FileSystemException;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Doctor;
use Orangecat\IndexerBoost\Model\Doctor\Report\LastReport;
use Orangecat\IndexerBoost\Model\Doctor\Report\LastReportStorage;
use Orangecat\IndexerBoost\Model\Doctor\Report\Summary;
use Orangecat\IndexerBoost\Model\Doctor\Severity;
use Orangecat\IndexerBoost\Model\Monitor\IndexerRunStats;
use Orangecat\IndexerBoost\Model\Monitor\RunStatsReader;
use Orangecat\IndexerBoost\ViewModel\DoctorPage;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class DoctorPageTest extends TestCase
{
    public function testLiveLightChecksAndSavedFindingsOfTheOtherChecks(): void
    {
        $live = [new CheckResult(Severity::Warn, 'INDEXER_MODE', 'realtime')];
        $doctor = $this->createMock(Doctor::class);
        $doctor->expects($this->once())->method('run')->with([], true)->willReturn($live);
        $orphans = new CheckResult(Severity::Warn, 'EAV_ORPHANS', '12 orphan rows');
        $storage = $this->createStub(LastReportStorage::class);
        $storage->method('load')->willReturn(new LastReport(
            new \DateTimeImmutable('2026-10-31 09:15:00', new \DateTimeZone('UTC')),
            [new CheckResult(Severity::Critical, 'INDEXER_MODE', 'stale'), $orphans]
        ));

        $page = new DoctorPage(
            $doctor,
            $storage,
            $this->createStub(RunStatsReader::class),
            new Summary(),
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame($live, $page->getLiveResults());
        $this->assertSame([$orphans], $page->getSavedResults());
        $this->assertSame('2026-10-31 09:15:00 UTC', $page->getSavedAt());
    }

    #[DataProvider('noReportProvider')]
    public function testWithoutAReadableReportNothingIsSaved(bool $throws): void
    {
        $storage = $this->createStub(LastReportStorage::class);
        if ($throws) {
            $storage->method('load')->willThrowException(new FileSystemException(__('Permission denied')));
        } else {
            $storage->method('load')->willReturn(null);
        }

        $page = new DoctorPage(
            $this->createStub(Doctor::class),
            $storage,
            $this->createStub(RunStatsReader::class),
            new Summary(),
            $this->createStub(LoggerInterface::class)
        );

        $this->assertNull($page->getSavedAt());
        $this->assertSame([], $page->getSavedResults());
    }

    /**
     * @return array<string,array{bool}>
     */
    public static function noReportProvider(): array
    {
        return ['missing' => [false], 'unreadable' => [true]];
    }

    public function testRunStatsComeFromThePassiveLogAndAFailureShowsNone(): void
    {
        $stats = [
            'customer_grid' => new IndexerRunStats('customer_grid', 2, 0, 0, 0, '2026-10-31 15:00:00', 0.38, 1, 0.33),
        ];
        $reader = $this->createStub(RunStatsReader::class);
        $reader->method('getStats')->willReturn($stats);
        $failing = $this->createStub(RunStatsReader::class);
        $failing->method('getStats')->willThrowException(new \RuntimeException('Table does not exist'));

        $page = new DoctorPage(
            $this->createStub(Doctor::class),
            $this->createStub(LastReportStorage::class),
            $reader,
            new Summary(),
            $this->createStub(LoggerInterface::class)
        );
        $broken = new DoctorPage(
            $this->createStub(Doctor::class),
            $this->createStub(LastReportStorage::class),
            $failing,
            new Summary(),
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame($stats, $page->getRunStats());
        $this->assertSame([], $broken->getRunStats());
    }

    public function testSummaryCountsFindingsPerSeverity(): void
    {
        $page = new DoctorPage(
            $this->createStub(Doctor::class),
            $this->createStub(LastReportStorage::class),
            $this->createStub(RunStatsReader::class),
            new Summary(),
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame(
            ['critical' => 1, 'warn' => 0, 'info' => 0, 'ok' => 2],
            $page->getSummary([
                new CheckResult(Severity::Ok, 'A', 'a'),
                new CheckResult(Severity::Critical, 'B', 'b'),
                new CheckResult(Severity::Ok, 'C', 'c'),
            ])
        );
    }
}
