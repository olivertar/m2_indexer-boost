<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Console\Command;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;
use Orangecat\IndexerBoost\Console\Command\DoctorCommand;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Doctor;
use Orangecat\IndexerBoost\Model\Doctor\Report\FormatterInterface;
use Orangecat\IndexerBoost\Model\Doctor\Report\LastReportStorage;
use Orangecat\IndexerBoost\Model\Doctor\Severity;
use Orangecat\IndexerBoost\Model\Repair\ReindexOutcome;
use Orangecat\IndexerBoost\Model\Repair\Repairer;
use Orangecat\IndexerBoost\Model\Repair\StuckIndexer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class DoctorCommandTest extends TestCase
{
    public function testDefaultFormatIsTableAndExitCodeIsZeroWithoutCritical(): void
    {
        $results = [new CheckResult(Severity::Warn, 'INDEXER_MODE', 'realtime')];
        $doctor = $this->createStub(Doctor::class);
        $doctor->method('run')->willReturn($results);
        $tester = new CommandTester($this->command($doctor));

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame("table:1\n", $tester->getDisplay());
    }

    public function testExitCodeIsOneWhenAnyFindingIsCritical(): void
    {
        $doctor = $this->createStub(Doctor::class);
        $doctor->method('run')->willReturn([
            new CheckResult(Severity::Critical, 'CRON_INDEXER', 'never ran'),
            new CheckResult(Severity::Ok, 'APPLICATION_LOCK', 'enabled'),
        ]);
        $tester = new CommandTester($this->command($doctor));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
    }

    public function testFormatOptionSelectsFormatter(): void
    {
        $doctor = $this->createStub(Doctor::class);
        $doctor->method('run')->willReturn([]);
        $tester = new CommandTester($this->command($doctor));

        $tester->execute(['--format' => 'json']);

        $this->assertSame("json:0\n", $tester->getDisplay());
    }

    public function testUnknownFormatIsAUsageErrorAndDoctorDoesNotRun(): void
    {
        $doctor = $this->createMock(Doctor::class);
        $doctor->expects($this->never())->method('run');
        $tester = new CommandTester($this->command($doctor));

        $exitCode = $tester->execute(['--format' => 'xml']);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('Unknown format "xml". Available: table, json', $tester->getDisplay());
    }

    public function testOnlyOptionIsSplitIntoCodes(): void
    {
        $doctor = $this->createMock(Doctor::class);
        $doctor->expects($this->once())
            ->method('run')
            ->with(['INDEXER_MODE', 'mysql_vars'])
            ->willReturn([]);
        $tester = new CommandTester($this->command($doctor));

        $tester->execute(['--only' => ' INDEXER_MODE, ,mysql_vars ']);
    }

    public function testUnknownCheckCodeIsAUsageError(): void
    {
        $doctor = $this->createStub(Doctor::class);
        $doctor->method('run')->willThrowException(
            new \InvalidArgumentException('Unknown check code(s): TYPO. Available: INDEXER_MODE')
        );
        $tester = new CommandTester($this->command($doctor));

        $exitCode = $tester->execute(['--only' => 'TYPO']);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('Unknown check code(s): TYPO', $tester->getDisplay());
    }

    public function testOutputOptionWritesReportToFileAndKeepsExitCode(): void
    {
        $doctor = $this->createStub(Doctor::class);
        $doctor->method('run')->willReturn([new CheckResult(Severity::Critical, 'CRON_INDEXER', 'never ran')]);
        $file = $this->createMock(File::class);
        $file->expects($this->once())
            ->method('filePutContents')
            ->with('/tmp/report.json', 'json:1')
            ->willReturn(6);
        $tester = new CommandTester($this->command($doctor, $file));

        $exitCode = $tester->execute(['--format' => 'json', '--output' => '/tmp/report.json']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame("Report written to /tmp/report.json\n", $tester->getDisplay());
    }

    public function testRepairWithoutInteractionReleasesOnlyStuckIndexersAndReindexesInvalidOnes(): void
    {
        $doctor = $this->createStub(Doctor::class);
        $doctor->method('run')->willReturn([]);
        $repairer = $this->createMock(Repairer::class);
        $repairer->method('findStuck')->willReturn([
            'catalog_product_price' => new StuckIndexer('catalog_product_price', 185, StuckIndexer::STUCK),
            'inventory' => new StuckIndexer('inventory', 200, StuckIndexer::DOUBTFUL),
            'catalogrule_rule' => new StuckIndexer('catalogrule_rule', 300, StuckIndexer::RUNNING),
        ]);
        $repairer->expects($this->once())->method('invalidate')->with('catalog_product_price')->willReturn(true);
        $repairer->method('reindexInvalid')->willReturn([new ReindexOutcome('catalog_product_price', 12.5)]);
        $tester = new CommandTester($this->command($doctor, null, $repairer));

        $exitCode = $tester->execute(
            ['--repair' => true],
            ['interactive' => false, 'capture_stderr_separately' => true]
        );

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame("table:0\n", $tester->getDisplay());
        $this->assertSame(
            "catalog_product_price: marked invalid (working for 185 min, lock free)\n"
            . "inventory: skipped, probably stuck; confirm it in an interactive run\n"
            . "catalogrule_rule: left untouched, reindex in progress (lock held)\n"
            . "catalog_product_price: reindexed in 12.5 s\n",
            $tester->getErrorOutput()
        );
    }

    public function testInteractiveRepairAsksBeforeEachIndexerAndDefaultsToNo(): void
    {
        $doctor = $this->createStub(Doctor::class);
        $doctor->method('run')->willReturn([]);
        $repairer = $this->createMock(Repairer::class);
        $repairer->method('findStuck')->willReturn([
            'catalog_product_price' => new StuckIndexer('catalog_product_price', 185, StuckIndexer::STUCK),
            'inventory' => new StuckIndexer('inventory', 200, StuckIndexer::DOUBTFUL),
            'customer_grid' => new StuckIndexer('customer_grid', 150, StuckIndexer::STUCK),
        ]);
        $repairer->expects($this->once())->method('invalidate')->with('inventory')->willReturn(true);
        $repairer->method('reindexInvalid')->willReturn([]);
        $tester = new CommandTester($this->command($doctor, null, $repairer));
        $tester->setInputs(['n', 'y', '']);

        $tester->execute(['--repair' => true], ['interactive' => true, 'capture_stderr_separately' => true]);

        $this->assertStringContainsString(
            'Mark inventory invalid? Working for 200 min, lock free, running statements not visible without '
            . 'the PROCESS privilege [y/N]',
            $tester->getErrorOutput()
        );
        $this->assertStringContainsString('catalog_product_price: skipped', $tester->getErrorOutput());
        $this->assertStringContainsString('customer_grid: skipped', $tester->getErrorOutput());
    }

    public function testFailedReindexDuringRepairIsReportedAndExitCodeIsOne(): void
    {
        $doctor = $this->createStub(Doctor::class);
        $doctor->method('run')->willReturn([]);
        $repairer = $this->createStub(Repairer::class);
        $repairer->method('findStuck')->willReturn([]);
        $repairer->method('reindexInvalid')->willReturn([
            new ReindexOutcome('catalog_product_price', 3.2, 'Deadlock found'),
            new ReindexOutcome('catalogrule_rule', 1.0),
        ]);
        $tester = new CommandTester($this->command($doctor, null, $repairer));

        $exitCode = $tester->execute(
            ['--repair' => true],
            ['interactive' => false, 'capture_stderr_separately' => true]
        );

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame(
            "catalog_product_price: reindex failed after 3.2 s: Deadlock found\n"
            . "catalogrule_rule: reindexed in 1 s\n",
            $tester->getErrorOutput()
        );
    }

    public function testExitCodeAfterRepairUsesTheIndexerFindingsCheckedAgain(): void
    {
        $doctor = $this->createStub(Doctor::class);
        $doctor->method('run')->willReturnCallback(static fn (array $only): array => match ($only) {
            [] => [
                new CheckResult(Severity::Critical, 'INDEXER_STUCK', 'Stuck indexers: catalog_product_price'),
                new CheckResult(Severity::Warn, 'INDEXER_MODE', 'realtime'),
            ],
            ['INDEXER_STATE', 'INDEXER_STUCK'] => [
                new CheckResult(Severity::Ok, 'INDEXER_STATE', 'All indexers valid'),
                new CheckResult(Severity::Ok, 'INDEXER_STUCK', 'No indexer left in "working"'),
            ],
            default => throw new \LogicException('Unexpected checks: ' . implode(',', $only)),
        });
        $repairer = $this->createStub(Repairer::class);
        $repairer->method('findStuck')->willReturn([]);
        $repairer->method('reindexInvalid')->willReturn([]);
        $tester = new CommandTester($this->command($doctor, null, $repairer));

        $exitCode = $tester->execute(
            ['--repair' => true],
            ['interactive' => false, 'capture_stderr_separately' => true]
        );

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringEndsWith(
            "After repair: ok INDEXER_STATE All indexers valid\n"
            . "After repair: ok INDEXER_STUCK No indexer left in \"working\"\n",
            $tester->getErrorOutput()
        );
    }

    public function testIndexerNoLongerStuckWhenReleasedIsReportedAndNothingIsReindexedIsSaid(): void
    {
        $doctor = $this->createStub(Doctor::class);
        $doctor->method('run')->willReturn([]);
        $repairer = $this->createStub(Repairer::class);
        $repairer->method('findStuck')->willReturn([
            'catalog_product_price' => new StuckIndexer('catalog_product_price', 185, StuckIndexer::STUCK),
        ]);
        $repairer->method('invalidate')->willReturn(false);
        $repairer->method('reindexInvalid')->willReturn([]);
        $tester = new CommandTester($this->command($doctor, null, $repairer));

        $tester->execute(['--repair' => true], ['interactive' => false, 'capture_stderr_separately' => true]);

        $this->assertSame(
            "catalog_product_price: left untouched, no longer stuck when checked again\n"
            . "No invalid indexer to reindex\n",
            $tester->getErrorOutput()
        );
    }

    public function testFullRunSavesTheReportForTheAdminPage(): void
    {
        $results = [new CheckResult(Severity::Warn, 'INDEXER_MODE', 'realtime')];
        $doctor = $this->createStub(Doctor::class);
        $doctor->method('run')->willReturn($results);
        $storage = $this->createMock(LastReportStorage::class);
        $storage->expects($this->once())->method('save')->with($results);
        $tester = new CommandTester($this->command($doctor, null, null, $storage));

        $tester->execute([]);
    }

    public function testPartialRunKeepsTheSavedReport(): void
    {
        $doctor = $this->createStub(Doctor::class);
        $doctor->method('run')->willReturn([]);
        $storage = $this->createMock(LastReportStorage::class);
        $storage->expects($this->never())->method('save');
        $tester = new CommandTester($this->command($doctor, null, null, $storage));

        $tester->execute(['--only' => 'INDEXER_MODE']);
    }

    public function testFailureToSaveTheReportIsAWarningAndKeepsTheExitCode(): void
    {
        $doctor = $this->createStub(Doctor::class);
        $doctor->method('run')->willReturn([]);
        $storage = $this->createStub(LastReportStorage::class);
        $storage->method('save')->willThrowException(
            new FileSystemException(__('The "var/indexer-boost" path is not writable.'))
        );
        $tester = new CommandTester($this->command($doctor, null, null, $storage));

        $exitCode = $tester->execute([], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame("table:0\n", $tester->getDisplay());
        $this->assertSame(
            "Could not save the report for the admin page: The \"var/indexer-boost\" path is not writable.\n",
            $tester->getErrorOutput()
        );
    }

    public function testRepairSavesTheFindingsCheckedAgain(): void
    {
        $mode = new CheckResult(Severity::Warn, 'INDEXER_MODE', 'realtime');
        $stuck = new CheckResult(Severity::Ok, 'INDEXER_STUCK', 'No indexer left in "working"');
        $doctor = $this->createStub(Doctor::class);
        $doctor->method('run')->willReturnCallback(static fn (array $only): array => $only
            ? [$stuck]
            : [new CheckResult(Severity::Critical, 'INDEXER_STUCK', 'Stuck indexers'), $mode]);
        $repairer = $this->createStub(Repairer::class);
        $repairer->method('findStuck')->willReturn([]);
        $repairer->method('reindexInvalid')->willReturn([]);
        $storage = $this->createMock(LastReportStorage::class);
        $storage->expects($this->once())->method('save')->with([$mode, $stuck]);
        $tester = new CommandTester($this->command($doctor, null, $repairer, $storage));

        $tester->execute(['--repair' => true], ['interactive' => false, 'capture_stderr_separately' => true]);
    }

    public function testFindingsCheckedAgainKeepTheWorstFirstOrder(): void
    {
        $mode = new CheckResult(Severity::Ok, 'INDEXER_MODE', 'scheduled');
        $state = new CheckResult(Severity::Critical, 'INDEXER_STATE', 'reindex failed');
        $doctor = $this->createStub(Doctor::class);
        $doctor->method('run')->willReturnCallback(static fn (array $only): array => $only
            ? [$state]
            : [new CheckResult(Severity::Critical, 'INDEXER_STUCK', 'Stuck indexers'), $mode]);
        $repairer = $this->createStub(Repairer::class);
        $repairer->method('findStuck')->willReturn([]);
        $repairer->method('reindexInvalid')->willReturn([]);
        $storage = $this->createMock(LastReportStorage::class);
        $storage->expects($this->once())->method('save')->with([$state, $mode]);
        $tester = new CommandTester($this->command($doctor, null, $repairer, $storage));

        $tester->execute(['--repair' => true], ['interactive' => false, 'capture_stderr_separately' => true]);
    }

    /**
     * @param Doctor $doctor
     * @param File|null $file
     * @param Repairer|null $repairer
     * @param LastReportStorage|null $storage
     * @return DoctorCommand
     */
    private function command(
        Doctor $doctor,
        ?File $file = null,
        ?Repairer $repairer = null,
        ?LastReportStorage $storage = null
    ): DoctorCommand {
        return new DoctorCommand(
            $doctor,
            $file ?? $this->createStub(File::class),
            $repairer ?? $this->createStub(Repairer::class),
            $storage ?? $this->createStub(LastReportStorage::class),
            ['table' => $this->formatter('table'), 'json' => $this->formatter('json')]
        );
    }

    /**
     * Formatter stub rendering "<name>:<number of findings>"
     *
     * @param string $name
     * @return FormatterInterface
     */
    private function formatter(string $name): FormatterInterface
    {
        $formatter = $this->createStub(FormatterInterface::class);
        $formatter->method('format')->willReturnCallback(
            static fn (array $results): string => $name . ':' . count($results)
        );
        return $formatter;
    }
}
