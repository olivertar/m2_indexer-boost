<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Report;

use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Report\JsonFormatter;
use Orangecat\IndexerBoost\Model\Doctor\Report\Summary;
use Orangecat\IndexerBoost\Model\Doctor\Severity;
use PHPUnit\Framework\TestCase;

class JsonFormatterTest extends TestCase
{
    public function testFormatsSummaryAndFindingsAsValidJson(): void
    {
        $critical = new CheckResult(
            Severity::Critical,
            'CRON_INDEXER',
            'indexer_update_all_views never ran',
            'Configure the Magento cron',
            'bin/magento cron:install',
            ['minutes_since_last_run' => null]
        );
        $ok = new CheckResult(Severity::Ok, 'APPLICATION_LOCK', 'Application lock is enabled');

        $json = (new JsonFormatter(new Summary()))->format([$critical, $ok]);

        $this->assertSame(
            [
                'summary' => ['critical' => 1, 'warn' => 0, 'info' => 0, 'ok' => 1],
                'findings' => [$critical->toArray(), $ok->toArray()],
            ],
            json_decode($json, true, 512, JSON_THROW_ON_ERROR)
        );
        $this->assertStringContainsString('"fix": "bin/magento cron:install"', $json);
    }
}
