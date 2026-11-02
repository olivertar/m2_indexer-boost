<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor;

use Orangecat\IndexerBoost\Model\Doctor\Severity;
use PHPUnit\Framework\TestCase;

class SeverityTest extends TestCase
{
    public function testWeightOrdersFromBestToWorst(): void
    {
        $this->assertLessThan(Severity::Info->weight(), Severity::Ok->weight());
        $this->assertLessThan(Severity::Warn->weight(), Severity::Info->weight());
        $this->assertLessThan(Severity::Critical->weight(), Severity::Warn->weight());
    }
}
