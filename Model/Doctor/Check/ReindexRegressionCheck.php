<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Config;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;
use Orangecat\IndexerBoost\Model\Monitor\RunStatsReader;

/**
 * Last successful full reindex of each indexer against the median of the previous ones (passive run log)
 */
class ReindexRegressionCheck implements CheckInterface
{
    public const CODE = 'REINDEX_REGRESSION';

    /**
     * @param RunStatsReader $statsReader
     * @param Config $config
     * @param int $requiredPreviousRuns successful full reindexes needed before the last one
     * @param float $ratio the last run regresses when it takes more than this many times the median
     * @param float $minSeconds ...and more than this many seconds
     */
    public function __construct(
        private readonly RunStatsReader $statsReader,
        private readonly Config $config,
        private readonly int $requiredPreviousRuns = 5,
        private readonly float $ratio = 2.0,
        private readonly float $minSeconds = 60.0
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * @inheritdoc
     */
    public function isHeavy(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function check(DoctorContext $context): array
    {
        if (!$this->config->isMonitorEnabled()) {
            return [
                new CheckResult(
                    Severity::Info,
                    self::CODE,
                    'The passive reindex monitor is disabled',
                    'Without the run log there is no history to detect reindexes that suddenly get slower.',
                    'bin/magento config:set indexer_boost/monitor/enabled 1'
                ),
            ];
        }

        $stats = $this->statsReader->getStats();
        if (!$stats) {
            return [
                new CheckResult(
                    Severity::Info,
                    self::CODE,
                    'No full reindex recorded yet in the passive run log'
                ),
            ];
        }

        $insufficient = [];
        $compared = [];
        $results = [];
        foreach ($stats as $indexerId => $indexerStats) {
            $last = $indexerStats->lastFullDuration;
            $median = $indexerStats->previousFullMedian;
            if ($last === null || $median === null || $indexerStats->previousFullRuns < $this->requiredPreviousRuns) {
                $insufficient[$indexerId] = $indexerStats->previousFullRuns + ($last === null ? 0 : 1);
                continue;
            }
            $compared[$indexerId] = ['last_s' => $last, 'median_s' => $median];
            if ($last > $this->ratio * $median && $last > $this->minSeconds) {
                $ratio = $median > 0 ? round($last / $median, 2) : null;
                $results[] = new CheckResult(
                    Severity::Warn,
                    self::CODE,
                    sprintf(
                        '%s: last full reindex (%s UTC) took %s s, %sx the median of the previous %d (%s s)',
                        $indexerId,
                        $indexerStats->lastFullStartedAt,
                        $this->number($last),
                        $ratio === null ? '∞' : $this->number($ratio),
                        $indexerStats->previousFullRuns,
                        $this->number($median)
                    ),
                    'Look for what changed before that run: catalog imports, new or changed catalog rules, websites '
                    . 'or customer groups, MySQL configuration or statistics, custom indexer code.',
                    'bin/magento indexer-boost:doctor --only=CATALOG_SIZE,CATALOG_RULES,TABLE_STATS,MYSQL_VARS',
                    ['indexer_id' => $indexerId, 'last_s' => $last, 'median_s' => $median, 'ratio' => $ratio]
                );
            }
        }

        if ($compared && !$results) {
            $results[] = new CheckResult(
                Severity::Ok,
                self::CODE,
                sprintf(
                    'No reindex regression (%d %s compared with its usual duration)',
                    count($compared),
                    count($compared) === 1 ? 'indexer' : 'indexers'
                ),
                '',
                '',
                ['indexers' => $compared]
            );
        }
        if ($insufficient) {
            $results[] = new CheckResult(
                Severity::Info,
                self::CODE,
                sprintf(
                    'Not enough history yet (%d successful full reindexes needed): %s',
                    $this->requiredPreviousRuns + 1,
                    $this->describe($insufficient, '%s (%d)')
                ),
                'The comparison starts once each indexer has its own history in the passive run log.',
                '',
                ['runs' => $insufficient]
            );
        }
        return $results;
    }

    /**
     * Number with up to 2 decimals and no trailing zeros, e.g. 400 or 75.5
     *
     * @param float $value
     * @return string
     */
    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Describe values per indexer, e.g. "catalog_product_price (3)"
     *
     * @param array<string,int|float> $values
     * @param string $format
     * @return string
     */
    private function describe(array $values, string $format): string
    {
        $parts = [];
        foreach ($values as $indexerId => $value) {
            $parts[] = sprintf($format, $indexerId, $value);
        }
        return implode(', ', $parts);
    }
}
