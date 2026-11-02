<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Db;

use Orangecat\IndexerBoost\Model\Doctor\Db\RangeIterator;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RangeIteratorTest extends TestCase
{
    use WriteGuardAdapterTrait;

    /**
     * @param array<string,mixed> $bounds
     * @param array<int,array{int,int}> $expected
     */
    #[DataProvider('rangeProvider')]
    public function testRangesCoverMinToMaxInSteps(array $bounds, int $step, array $expected): void
    {
        $iterator = $this->iterator($bounds);

        $this->assertSame($expected, iterator_to_array($iterator->ranges('pfx_t', 'entity_id', $step), false));
        $this->assertSame(
            ['SELECT MIN(entity_id) AS min_id, MAX(entity_id) AS max_id FROM pfx_t'],
            $this->writeGuardQueries
        );
        $this->assertNoWriteAttempts();
    }

    /**
     * @return array<string,array{array<string,mixed>,int,array<int,array{int,int}>}>
     */
    public static function rangeProvider(): array
    {
        return [
            'empty table' => [['min_id' => null, 'max_id' => null], 10, []],
            'single row' => [['min_id' => '5', 'max_id' => '5'], 10, [[5, 5]]],
            'exact multiple' => [['min_id' => '1', 'max_id' => '20'], 10, [[1, 10], [11, 20]]],
            'gaps and partial last range' => [
                ['min_id' => '3', 'max_id' => '25'],
                10,
                [[3, 12], [13, 22], [23, 25]],
            ],
        ];
    }

    public function testStepMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        iterator_to_array($this->iterator(['min_id' => '1', 'max_id' => '2'])->ranges('pfx_t', 'entity_id', 0));
    }

    /**
     * @param array<string,mixed> $bounds
     * @return RangeIterator
     */
    private function iterator(array $bounds): RangeIterator
    {
        $adapter = $this->createWriteGuardAdapter(static fn (): array => $bounds);
        return new RangeIterator(new ReadOnlyConnection($this->createResourceConnection($adapter)));
    }
}
