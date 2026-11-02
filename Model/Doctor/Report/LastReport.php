<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Report;

use Orangecat\IndexerBoost\Model\Doctor\CheckResult;

/**
 * Doctor report saved by the last full CLI run
 */
class LastReport
{
    /**
     * @param \DateTimeImmutable $generatedAt UTC
     * @param CheckResult[] $results
     */
    public function __construct(
        public readonly \DateTimeImmutable $generatedAt,
        public readonly array $results
    ) {
    }
}
