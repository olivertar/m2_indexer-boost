<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Orangecat\IndexerBoost\Model\Doctor\Check\EavRedundantStoreValuesCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\RangeIterator;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class EavRedundantStoreValuesCheckTest extends TestCase
{
    use WriteGuardAdapterTrait;

    /**
     * Binds of the count queries, in order
     *
     * @var array<int,array<mixed>>
     */
    private array $binds = [];

    public function testCountsAreSummedOverEntityRangesPerTable(): void
    {
        // varchar: ids 1..25 -> ranges [1,10] [11,20] [21,25], 4 redundant rows in each; int: 1 range, 3 rows
        $result = $this->findings(
            ['int' => [1, 8], 'varchar' => [1, 25]],
            ['int' => 3, 'varchar' => 4],
            'entity_id'
        )[0];

        $this->assertSame(
            [
                'severity' => 'info',
                'code' => 'EAV_REDUNDANT_STORE_VALUES',
                'message' => '15 store-view EAV values equal to the default value: catalog_product_entity_int (3), '
                    . 'catalog_product_entity_varchar (12)',
                'recommendation' => 'These rows add work to every EAV join of the indexers without changing any '
                    . 'value. They usually come from imports or from saving a store view without "Use Default '
                    . 'Value". Deleting them makes those store views follow the default value, including future '
                    . 'changes: confirm with the business and clean them on staging first.',
                'fix' => 'Delete the store-view rows equal to store_id 0 on staging, by entity ranges (the doctor '
                    . 'only counts, it never deletes)',
                'metrics' => [
                    'redundant' => ['catalog_product_entity_int' => 3, 'catalog_product_entity_varchar' => 12],
                    'range_size' => 10,
                ],
            ],
            $result
        );
        $this->assertNoWriteAttempts();
    }

    public function testEveryValueQueryIsBoundedToAnEntityRange(): void
    {
        $this->findings(['varchar' => [1, 25], 'text' => [5, 5]], [], 'entity_id');

        $countQueries = array_values(array_filter(
            $this->writeGuardQueries,
            static fn (string $sql): bool => !str_contains($sql, 'MIN(')
        ));
        $this->assertCount(4, $countQueries);
        foreach ($countQueries as $sql) {
            $this->assertStringContainsString('s.entity_id BETWEEN ? AND ?', $sql);
        }
        $this->assertSame([[1, 10], [11, 20], [21, 25], [5, 5]], $this->binds);
    }

    public function testTextValuesAreComparedCaseSensitively(): void
    {
        $this->findings(['int' => [1, 1], 'varchar' => [1, 1]], [], 'entity_id');

        [$intSql, $varcharSql] = array_values(array_filter(
            $this->writeGuardQueries,
            static fn (string $sql): bool => !str_contains($sql, 'MIN(')
        ));
        $this->assertStringContainsString('s.value <=> d.value', $intSql);
        $this->assertStringContainsString('BINARY s.value <=> BINARY d.value', $varcharSql);
    }

    public function testNoRedundantValuesIsOk(): void
    {
        $result = $this->findings(['int' => [1, 8]], [], 'entity_id')[0];

        $this->assertSame(
            ['ok', 'No store-view EAV values equal to the default value (5 tables checked)'],
            [$result['severity'], $result['message']]
        );
    }

    public function testManyRedundantValuesIsWarn(): void
    {
        $result = $this->findings(['int' => [1, 8]], ['int' => 100000], 'entity_id')[0];

        $this->assertSame('warn', $result['severity']);
    }

    public function testCommerceRowIdLinkFieldIsUsed(): void
    {
        $this->findings(['int' => [1, 1]], [], 'row_id');

        $this->assertStringContainsString('MIN(row_id)', $this->writeGuardQueries[0]);
        $this->assertStringContainsString('d.row_id = s.row_id', $this->writeGuardQueries[1]);
        $this->assertStringContainsString('s.row_id BETWEEN ? AND ?', $this->writeGuardQueries[1]);
    }

    /**
     * @param array<string,array{int,int}> $bounds min/max link id per backend type; missing types are empty
     * @param array<string,int> $perRange redundant rows returned for each range of a backend type
     * @param string $linkField
     * @return array<int,array<string,mixed>>
     */
    private function findings(array $bounds, array $perRange, string $linkField): array
    {
        $adapter = $this->createWriteGuardAdapter(
            function (string $sql, string $method, array $bind) use ($bounds, $perRange): mixed {
                preg_match('~FROM pfx_catalog_product_entity_(\w+)~', $sql, $match);
                $type = $match[1] ?? throw new \LogicException($sql);
                if (str_contains($sql, 'MIN(')) {
                    return isset($bounds[$type])
                        ? ['min_id' => (string)$bounds[$type][0], 'max_id' => (string)$bounds[$type][1]]
                        : ['min_id' => null, 'max_id' => null];
                }
                $this->binds[] = $bind;
                return (string)($perRange[$type] ?? 0);
            }
        );
        $connection = new ReadOnlyConnection($this->createResourceConnection($adapter));
        $check = new EavRedundantStoreValuesCheck(
            $connection,
            new RangeIterator($connection),
            $this->metadataPool($linkField),
            10,
            100000
        );

        $this->assertTrue($check->isHeavy());
        return array_map(static fn ($result): array => $result->toArray(), $check->check(new DoctorContext()));
    }

    /**
     * @param string $linkField
     * @return MetadataPool
     */
    private function metadataPool(string $linkField): MetadataPool
    {
        $metadata = $this->createStub(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn($linkField);
        $pool = $this->createStub(MetadataPool::class);
        $pool->method('getMetadata')->willReturnMap([[ProductInterface::class, $metadata]]);
        return $pool;
    }
}
