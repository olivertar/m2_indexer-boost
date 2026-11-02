<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Report;

use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Report\MarkdownFormatter;
use Orangecat\IndexerBoost\Model\Doctor\Report\Summary;
use Orangecat\IndexerBoost\Model\Doctor\Severity;
use PHPUnit\Framework\TestCase;

class MarkdownFormatterTest extends TestCase
{
    public function testFormatsSummaryAndEscapedTable(): void
    {
        $results = [
            new CheckResult(
                Severity::Warn,
                'MYSQL_VARS',
                "tmp | heap\ntoo small",
                'Set both to 64-256 MB',
                'tmp = 128M | heap = 128M'
            ),
            new CheckResult(Severity::Ok, 'APPLICATION_LOCK', 'Application lock is enabled'),
        ];

        $expected = <<<'MD'
# Indexer Boost doctor report

**Summary:** 0 critical, 1 warn, 0 info, 1 ok

| Severity | Code | Message | Recommendation | Fix |
|---|---|---|---|---|
| warn | MYSQL_VARS | tmp \| heap<br>too small | Set both to 64-256 MB | `tmp = 128M \| heap = 128M` |
| ok | APPLICATION_LOCK | Application lock is enabled |  |  |

MD;

        $this->assertSame($expected, (new MarkdownFormatter(new Summary()))->format($results));
    }
}
