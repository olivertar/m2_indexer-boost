<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Report;

use Orangecat\IndexerBoost\Model\Doctor\CheckResult;

/**
 * Renders doctor findings in an output format
 *
 * @api
 */
interface FormatterInterface
{
    /**
     * Render the findings
     *
     * @param CheckResult[] $results Findings sorted worst first
     * @return string
     */
    public function format(array $results): string;
}
