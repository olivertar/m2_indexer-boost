<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Fake;

use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WriteGuardAdapterTraitTest extends TestCase
{
    use WriteGuardAdapterTrait;

    /**
     * @param callable(AdapterInterface): mixed $write
     */
    #[DataProvider('swallowedWriteProvider')]
    public function testSwallowedWriteIsStillRecorded(callable $write): void
    {
        $adapter = $this->createWriteGuardAdapter(static fn (): array => []);

        try {
            $write($adapter);
        } catch (\LogicException) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            // Code under test may swallow the exception; the violation must survive.
        }

        $this->assertCount(1, $this->writeGuardViolations);
    }

    /**
     * @return array<string,array{callable(AdapterInterface): mixed}>
     */
    public static function swallowedWriteProvider(): array
    {
        return [
            'insert method' => [static fn (AdapterInterface $a) => $a->insert('t', ['a' => 1])],
            'delete method' => [static fn (AdapterInterface $a) => $a->delete('t')],
            'truncate method' => [static fn (AdapterInterface $a) => $a->truncateTable('t')],
            'raw update' => [static fn (AdapterInterface $a) => $a->query('UPDATE t SET a = 1')],
            'raw alter' => [static fn (AdapterInterface $a) => $a->fetchAll('ALTER TABLE t ADD b INT')],
        ];
    }
}
