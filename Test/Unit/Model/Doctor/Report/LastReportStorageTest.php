<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Report;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Report\LastReportStorage;
use Orangecat\IndexerBoost\Model\Doctor\Severity;
use Orangecat\IndexerBoost\Model\Monitor\Clock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LastReportStorageTest extends TestCase
{
    /**
     * @var array<string,string> in-memory var/ directory: relative path => contents
     */
    private array $files = [];

    public function testSavedReportIsLoadedBackWithItsDate(): void
    {
        $results = [
            new CheckResult(Severity::Critical, 'CRON_INDEXER', 'never ran', 'Run cron', 'crontab -e', ['jobs' => 0]),
            new CheckResult(Severity::Ok, 'APPLICATION_LOCK', 'enabled'),
        ];

        $this->storage()->save($results);
        $report = $this->storage()->load();

        $this->assertNotNull($report);
        $this->assertSame('2026-10-31 09:15:00', $report->generatedAt->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $report->generatedAt->getTimezone()->getName());
        $this->assertEquals($results, $report->results);
        $this->assertSame(['indexer-boost/doctor-last.json'], array_keys($this->files));
    }

    public function testMissingReportIsNull(): void
    {
        $this->assertNull($this->storage()->load());
    }

    /**
     * @return array<string,array{string}>
     */
    public static function unreadableProvider(): array
    {
        return [
            'not json' => ['{"version":1,'],
            'not an object' => ['"report"'],
            'other format version' => ['{"version":2,"generated_at":"2026-10-31T09:15:00Z","findings":[]}'],
            'bad date' => ['{"version":1,"generated_at":"yesterday","findings":[]}'],
            'findings not a list' => ['{"version":1,"generated_at":"2026-10-31T09:15:00Z","findings":"none"}'],
            'unknown severity' => [
                '{"version":1,"generated_at":"2026-10-31T09:15:00Z","findings":[{"severity":"fatal","code":"X",'
                . '"message":"m","recommendation":"","fix":"","metrics":[]}]}',
            ],
            'missing field' => [
                '{"version":1,"generated_at":"2026-10-31T09:15:00Z","findings":[{"severity":"ok","code":"X"}]}',
            ],
        ];
    }

    #[DataProvider('unreadableProvider')]
    public function testUnreadableReportIsNull(string $contents): void
    {
        $this->files[LastReportStorage::PATH] = $contents;

        $this->assertNull($this->storage()->load());
    }

    /**
     * Storage over the in-memory var/ directory of this test
     *
     * @return LastReportStorage
     */
    private function storage(): LastReportStorage
    {
        $write = $this->createStub(WriteInterface::class);
        $write->method('writeFile')->willReturnCallback(function (string $path, string $content): int {
            $this->files[$path] = $content;
            return strlen($content);
        });
        $read = $this->createStub(ReadInterface::class);
        $read->method('isFile')->willReturnCallback(fn (string $path): bool => isset($this->files[$path]));
        $read->method('readFile')->willReturnCallback(fn (string $path): string => $this->files[$path]);
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturnMap([[DirectoryList::VAR_DIR, 'file', $write]]);
        $filesystem->method('getDirectoryRead')->willReturnMap([[DirectoryList::VAR_DIR, 'file', $read]]);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(
            new \DateTimeImmutable('2026-10-31 09:15:00', new \DateTimeZone('UTC'))
        );

        return new LastReportStorage($filesystem, $clock);
    }
}
