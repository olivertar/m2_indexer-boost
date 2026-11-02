<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Report;

use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Counts findings per severity, worst first
 */
class Summary
{
    /**
     * Count findings per severity
     *
     * @param CheckResult[] $results
     * @return array<string,int> Severity value => count, ordered critical, warn, info, ok
     */
    public function count(array $results): array
    {
        $summary = [];
        foreach ([Severity::Critical, Severity::Warn, Severity::Info, Severity::Ok] as $severity) {
            $summary[$severity->value] = 0;
        }
        foreach ($results as $result) {
            $summary[$result->getSeverity()->value]++;
        }
        return $summary;
    }

    /**
     * Describe the counts as text, e.g. "1 critical, 0 warn, 0 info, 3 ok"
     *
     * @param CheckResult[] $results
     * @return string
     */
    public function describe(array $results): string
    {
        $parts = [];
        foreach ($this->count($results) as $severity => $count) {
            $parts[] = $count . ' ' . $severity;
        }
        return implode(', ', $parts);
    }
}
