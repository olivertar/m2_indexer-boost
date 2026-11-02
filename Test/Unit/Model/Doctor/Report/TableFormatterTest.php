<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Report;

use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Report\Summary;
use Orangecat\IndexerBoost\Model\Doctor\Report\TableFormatter;
use Orangecat\IndexerBoost\Model\Doctor\Severity;
use PHPUnit\Framework\TestCase;

class TableFormatterTest extends TestCase
{
    public function testFormatsFindingsAsConsoleTableWithSummary(): void
    {
        $results = [
            new CheckResult(
                Severity::Critical,
                'CRON_INDEXER',
                'indexer_update_all_views never ran',
                'Configure the Magento cron',
                'bin/magento cron:install'
            ),
            new CheckResult(Severity::Ok, 'APPLICATION_LOCK', 'Application lock is enabled'),
        ];

        $output = (new TableFormatter(new Summary()))->format($results);
        $lines = explode("\n", rtrim($output));

        $header = '/\| Severity\s+\| Code\s+\| Message\s+\| Recommendation \/ Fix\s+\|/';
        $criticalRow = '/\| CRITICAL\s+\| CRON_INDEXER\s+\| indexer_update_all_views never ran/';
        $fixLine = '/\|\s+\|\s+\|\s+\| bin\/magento cron:install\s+\|/';
        $okRow = '/\| OK\s+\| APPLICATION_LOCK\s+\| Application lock is enabled/';

        $this->assertMatchesRegularExpression('/^\+-+\+/', $lines[0]);
        $this->assertMatchesRegularExpression($header, $lines[1]);
        $this->assertMatchesRegularExpression($criticalRow, $output);
        $this->assertMatchesRegularExpression($fixLine, $output);
        $this->assertMatchesRegularExpression($okRow, $output);
        $this->assertSame('Summary: 1 critical, 0 warn, 0 info, 1 ok', end($lines));
    }
}
