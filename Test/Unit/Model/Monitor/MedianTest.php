<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Monitor;

use Orangecat\IndexerBoost\Model\Monitor\Median;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MedianTest extends TestCase
{
    /**
     * @param float[] $values
     */
    #[DataProvider('medianProvider')]
    public function testMedian(array $values, ?float $expected): void
    {
        $this->assertSame($expected, (new Median())->calculate($values));
    }

    /**
     * @return array<string,array{float[],?float}>
     */
    public static function medianProvider(): array
    {
        return [
            'empty' => [[], null],
            'single' => [[12.5], 12.5],
            'odd, unsorted' => [[30.0, 10.0, 20.0], 20.0],
            'even' => [[40.0, 10.0, 30.0, 20.0], 25.0],
            'outlier does not move it' => [[10.0, 11.0, 12.0, 13.0, 900.0], 12.0],
        ];
    }
}
