<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Console\Command;

use Magento\Framework\Filesystem\Driver\File;
use Orangecat\IndexerBoost\Model\Doctor\Check\IndexerStateCheck;
use Orangecat\IndexerBoost\Model\Doctor\Check\IndexerStuckCheck;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Doctor;
use Orangecat\IndexerBoost\Model\Doctor\Report\FormatterInterface;
use Orangecat\IndexerBoost\Model\Doctor\Report\LastReportStorage;
use Orangecat\IndexerBoost\Model\Doctor\Severity;
use Orangecat\IndexerBoost\Model\Repair\Repairer;
use Orangecat\IndexerBoost\Model\Repair\StuckIndexer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Read-only diagnosis of the indexing setup
 */
class DoctorCommand extends Command
{
    private const NAME = 'indexer-boost:doctor';
    private const OPTION_FORMAT = 'format';
    private const OPTION_ONLY = 'only';
    private const OPTION_OUTPUT = 'output';
    private const OPTION_REPAIR = 'repair';

    /**
     * Checks whose findings the repair may change, run again after it
     */
    private const REPAIRED_CHECKS = [IndexerStateCheck::CODE, IndexerStuckCheck::CODE];

    /**
     * Why an indexer working for too long is left untouched, by verdict
     */
    private const UNTOUCHED = [
        StuckIndexer::RUNNING => 'reindex in progress (lock held)',
        StuckIndexer::ACTIVE => 'still writing its tables',
        StuckIndexer::UNVERIFIABLE => 'cannot be verified without the application lock',
    ];

    /**
     * Evidence shown when asking to release an indexer, by verdict
     */
    private const EVIDENCE = [
        StuckIndexer::STUCK => 'no running statement on its tables',
        StuckIndexer::DOUBTFUL => 'running statements not visible without the PROCESS privilege',
    ];

    /**
     * @param Doctor $doctor
     * @param File $file
     * @param Repairer $repairer
     * @param LastReportStorage $lastReport
     * @param array<string,FormatterInterface> $formatters
     */
    public function __construct(
        private readonly Doctor $doctor,
        private readonly File $file,
        private readonly Repairer $repairer,
        private readonly LastReportStorage $lastReport,
        private readonly array $formatters = []
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Diagnose configuration, database and data issues that slow down indexing')
            ->addOption(
                self::OPTION_FORMAT,
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: ' . implode('|', array_keys($this->formatters)),
                'table'
            )
            ->addOption(
                self::OPTION_ONLY,
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated check codes to run, e.g. INDEXER_MODE,MYSQL_VARS'
            )
            ->addOption(
                self::OPTION_OUTPUT,
                null,
                InputOption::VALUE_REQUIRED,
                'Write the report to this file instead of the console'
            )
            ->addOption(
                self::OPTION_REPAIR,
                null,
                InputOption::VALUE_NONE,
                'After the report, mark stuck indexers invalid and reindex every invalid indexer'
            );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string)$input->getOption(self::OPTION_FORMAT);
        if (!isset($this->formatters[$format])) {
            $output->writeln(sprintf(
                '<error>Unknown format "%s". Available: %s</error>',
                $format,
                implode(', ', array_keys($this->formatters))
            ));
            return Command::INVALID;
        }

        $codes = $this->parseCodes((string)$input->getOption(self::OPTION_ONLY));
        try {
            $results = $this->doctor->run($codes);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        }

        $report = $this->formatters[$format]->format($results);
        $outputFile = (string)$input->getOption(self::OPTION_OUTPUT);
        if ($outputFile !== '') {
            $this->file->filePutContents($outputFile, $report);
            $output->writeln('Report written to ' . $outputFile);
        } else {
            $output->writeln(rtrim($report, "\n"));
        }

        // Progress goes to stderr so a JSON or Markdown report on stdout stays parseable
        $log = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $repaired = true;
        if ($input->getOption(self::OPTION_REPAIR)) {
            $repaired = $this->repair($input, $log);
            $results = $this->checkAgain($results, $codes, $log);
        }
        if (!$codes) {
            $this->saveLastReport($results, $log);
        }

        return !$repaired || $this->hasCritical($results) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Release stuck indexers, then reindex the invalid ones
     *
     * @param InputInterface $input
     * @param OutputInterface $log
     * @return bool false when a reindex failed
     */
    private function repair(InputInterface $input, OutputInterface $log): bool
    {
        foreach ($this->repairer->findStuck() as $indexer) {
            $id = $indexer->indexerId;
            if (isset(self::UNTOUCHED[$indexer->verdict])) {
                $log->writeln(sprintf('%s: left untouched, %s', $id, self::UNTOUCHED[$indexer->verdict]));
                continue;
            }
            if ($indexer->verdict === StuckIndexer::DOUBTFUL && !$input->isInteractive()) {
                $log->writeln(sprintf('%s: skipped, probably stuck; confirm it in an interactive run', $id));
                continue;
            }
            if ($input->isInteractive() && !$this->confirm($input, $log, $indexer)) {
                $log->writeln(sprintf('%s: skipped', $id));
                continue;
            }
            if (!$this->repairer->invalidate($id)) {
                $log->writeln(sprintf('%s: left untouched, no longer stuck when checked again', $id));
                continue;
            }
            $log->writeln(sprintf(
                '%s: marked invalid (working for %d min, lock free)',
                $id,
                $indexer->minutesWorking
            ));
        }

        $outcomes = $this->repairer->reindexInvalid();
        if (!$outcomes) {
            $log->writeln('No invalid indexer to reindex');
        }
        $succeeded = true;
        foreach ($outcomes as $outcome) {
            if ($outcome->error !== null) {
                $succeeded = false;
                $log->writeln(sprintf(
                    '<error>%s: reindex failed after %s s: %s</error>',
                    $outcome->indexerId,
                    $outcome->seconds,
                    $outcome->error
                ));
                continue;
            }
            $log->writeln(sprintf('%s: reindexed in %s s', $outcome->indexerId, $outcome->seconds));
        }
        return $succeeded;
    }

    /**
     * Keep the findings for the admin page; failing to do so never fails the diagnosis
     *
     * Only full runs are saved: a partial run would replace the full report the admin page shows.
     *
     * @param CheckResult[] $results
     * @param OutputInterface $log
     * @return void
     */
    private function saveLastReport(array $results, OutputInterface $log): void
    {
        try {
            $this->lastReport->save($results);
        } catch (\Exception $e) {
            $log->writeln('<comment>Could not save the report for the admin page: ' . $e->getMessage() . '</comment>');
        }
    }

    /**
     * Replace the findings the repair may have changed with a fresh run of their checks
     *
     * @param CheckResult[] $results
     * @param string[] $codes checks selected with --only, empty for all
     * @param OutputInterface $log
     * @return CheckResult[]
     */
    private function checkAgain(array $results, array $codes, OutputInterface $log): array
    {
        $recheck = $codes
            ? array_values(array_intersect(self::REPAIRED_CHECKS, array_map('strtoupper', $codes)))
            : self::REPAIRED_CHECKS;
        if (!$recheck) {
            return $results;
        }

        $fresh = $this->doctor->run($recheck);
        foreach ($fresh as $result) {
            $finding = $result->toArray();
            $log->writeln(sprintf(
                'After repair: %s %s %s',
                $finding['severity'],
                $finding['code'],
                $finding['message']
            ));
        }
        $kept = array_filter(
            $results,
            static fn (CheckResult $result): bool => !in_array($result->toArray()['code'], $recheck, true)
        );
        $merged = array_merge($kept, $fresh);
        usort(
            $merged,
            static fn (CheckResult $a, CheckResult $b): int =>
                $b->getSeverity()->weight() <=> $a->getSeverity()->weight()
        );
        return $merged;
    }

    /**
     * Ask the operator to release one indexer
     *
     * @param InputInterface $input
     * @param OutputInterface $log
     * @param StuckIndexer $indexer
     * @return bool
     */
    private function confirm(InputInterface $input, OutputInterface $log, StuckIndexer $indexer): bool
    {
        $question = new ConfirmationQuestion(sprintf(
            'Mark %s invalid? Working for %d min, lock free, %s [y/N] ',
            $indexer->indexerId,
            $indexer->minutesWorking,
            self::EVIDENCE[$indexer->verdict] ?? ''
        ), false);
        return (bool)(new QuestionHelper())->ask($input, $log, $question);
    }

    /**
     * Split a comma-separated list of check codes
     *
     * @param string $value
     * @return string[]
     */
    private function parseCodes(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $code): bool => $code !== ''
        ));
    }

    /**
     * Whether any finding is critical
     *
     * @param CheckResult[] $results
     * @return bool
     */
    private function hasCritical(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->getSeverity() === Severity::Critical) {
                return true;
            }
        }
        return false;
    }
}
