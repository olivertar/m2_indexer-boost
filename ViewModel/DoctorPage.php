<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Doctor;
use Orangecat\IndexerBoost\Model\Doctor\Report\LastReport;
use Orangecat\IndexerBoost\Model\Doctor\Report\LastReportStorage;
use Orangecat\IndexerBoost\Model\Doctor\Report\Summary;
use Orangecat\IndexerBoost\Model\Monitor\IndexerRunStats;
use Orangecat\IndexerBoost\Model\Monitor\RunStatsReader;
use Psr\Log\LoggerInterface;

/**
 * Read-only admin page: light checks run live, heavy ones from the last CLI report, and the passive run log
 */
class DoctorPage implements ArgumentInterface
{
    /**
     * @var CheckResult[]|null
     */
    private ?array $liveResults = null;

    /**
     * @var bool
     */
    private bool $lastReportLoaded = false;

    /**
     * @var LastReport|null
     */
    private ?LastReport $lastReport = null;

    /**
     * @param Doctor $doctor
     * @param LastReportStorage $lastReportStorage
     * @param RunStatsReader $runStats
     * @param Summary $summary
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Doctor $doctor,
        private readonly LastReportStorage $lastReportStorage,
        private readonly RunStatsReader $runStats,
        private readonly Summary $summary,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Findings of the light checks, run now
     *
     * @return CheckResult[]
     */
    public function getLiveResults(): array
    {
        return $this->liveResults ??= $this->doctor->run([], true);
    }

    /**
     * Findings of the last CLI report for the checks that did not run live
     *
     * @return CheckResult[]
     */
    public function getSavedResults(): array
    {
        $report = $this->getLastReport();
        if ($report === null) {
            return [];
        }
        $liveCodes = array_map(
            static fn (CheckResult $result): string => $result->toArray()['code'],
            $this->getLiveResults()
        );
        return array_values(array_filter(
            $report->results,
            static fn (CheckResult $result): bool => !in_array($result->toArray()['code'], $liveCodes, true)
        ));
    }

    /**
     * Date of the last CLI report, null when there is none
     *
     * @return string|null
     */
    public function getSavedAt(): ?string
    {
        $report = $this->getLastReport();
        return $report === null ? null : $report->generatedAt->format('Y-m-d H:i:s') . ' UTC';
    }

    /**
     * Passive run log summary per indexer
     *
     * @return array<string,IndexerRunStats>
     */
    public function getRunStats(): array
    {
        try {
            return $this->runStats->getStats();
        } catch (\Exception $e) {
            $this->logger->warning('Indexer Boost: cannot read the run log: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Findings per severity, worst first
     *
     * @param CheckResult[] $results
     * @return array<string,int>
     */
    public function getSummary(array $results): array
    {
        return $this->summary->count($results);
    }

    /**
     * Last CLI report, loaded once
     *
     * @return LastReport|null
     */
    private function getLastReport(): ?LastReport
    {
        if (!$this->lastReportLoaded) {
            $this->lastReportLoaded = true;
            try {
                $this->lastReport = $this->lastReportStorage->load();
            } catch (\Exception $e) {
                // Unreadable var/ directory: the page asks for a new CLI run, as when there is no report
                $this->logger->warning('Indexer Boost: cannot read the last doctor report: ' . $e->getMessage());
                $this->lastReport = null;
            }
        }
        return $this->lastReport;
    }
}
