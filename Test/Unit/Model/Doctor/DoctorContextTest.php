<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor;

use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use PHPUnit\Framework\TestCase;

class DoctorContextTest extends TestCase
{
    public function testRememberComputesEachKeyOnlyOnce(): void
    {
        $context = new DoctorContext();
        $calls = [];
        $loader = static function () use (&$calls): int {
            $calls[] = true;
            return count($calls) * 1000;
        };

        $this->assertSame(1000, $context->remember('catalog_size', $loader));
        $this->assertSame(1000, $context->remember('catalog_size', $loader));
        $this->assertSame(2000, $context->remember('db_version', $loader));
        $this->assertCount(2, $calls);
    }
}
