<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor;

use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Severity;
use PHPUnit\Framework\TestCase;

class CheckResultTest extends TestCase
{
    public function testToArrayExposesAllFields(): void
    {
        $result = new CheckResult(
            Severity::Warn,
            'INDEXER_MODE',
            'catalog_product_price is in "Update on Save" mode',
            'Switch the indexer to "Update by Schedule"',
            'bin/magento indexer:set-mode schedule catalog_product_price',
            ['indexers' => 1]
        );

        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'INDEXER_MODE',
                'message' => 'catalog_product_price is in "Update on Save" mode',
                'recommendation' => 'Switch the indexer to "Update by Schedule"',
                'fix' => 'bin/magento indexer:set-mode schedule catalog_product_price',
                'metrics' => ['indexers' => 1],
            ],
            $result->toArray()
        );
    }
}
