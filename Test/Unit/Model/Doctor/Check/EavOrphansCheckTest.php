<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Orangecat\IndexerBoost\Model\Doctor\Check\EavOrphansCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\RangeIterator;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class EavOrphansCheckTest extends TestCase
{
    use WriteGuardAdapterTrait;

    private const NONE = [
        'missing_entity' => '0',
        'foreign_attribute' => '0',
        'wrong_backend' => '0',
        'missing_store' => '0',
    ];

    /**
     * Binds of the orphan queries, in order
     *
     * @var array<int,array<mixed>>
     */
    private array $binds = [];

    public function testOrphansAreSummedOverRangesPerTableAndKind(): void
    {
        // int: ranges [1,10] [11,15]; varchar: one range
        $result = $this->findings(
            ['int' => [1, 15], 'varchar' => [1, 3]],
            [
                'int' => ['missing_entity' => '2', 'foreign_attribute' => '0', 'wrong_backend' => '1',
                    'missing_store' => null],
                'varchar' => ['missing_entity' => '0', 'foreign_attribute' => '5', 'wrong_backend' => '0',
                    'missing_store' => '1'],
            ],
            'entity_id'
        )[0];

        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'EAV_ORPHANS',
                'message' => '12 orphan EAV values: catalog_product_entity_int (4 missing product, 2 attribute '
                    . 'stored in another backend table), catalog_product_entity_varchar (5 unknown or non-product '
                    . 'attribute, 1 missing store)',
                'recommendation' => 'No indexer or storefront page reads these rows, but EAV queries still scan '
                    . 'them. Missing products or stores mean foreign keys were dropped (for example by a partial '
                    . 'import or a dump restored without them). Values in another backend table are left behind '
                    . 'when an attribute backend_type is changed.',
                'fix' => 'Delete the orphan rows on staging, by entity ranges (the doctor only counts, it never '
                    . 'deletes); restore missing foreign keys with bin/magento setup:upgrade',
                'metrics' => [
                    'orphans' => [
                        'catalog_product_entity_int' => ['missing_entity' => 4, 'wrong_backend' => 2],
                        'catalog_product_entity_varchar' => ['foreign_attribute' => 5, 'missing_store' => 1],
                    ],
                    'range_size' => 10,
                ],
            ],
            $result
        );
        $this->assertNoWriteAttempts();
    }

    public function testEveryValueQueryIsBoundedToAnEntityRangeAndBindsTheProductType(): void
    {
        $this->findings(['decimal' => [1, 15]], [], 'entity_id');

        $orphanQueries = array_values(array_filter(
            $this->writeGuardQueries,
            static fn (string $sql): bool => str_contains($sql, 'missing_entity')
        ));
        $this->assertCount(2, $orphanQueries);
        foreach ($orphanQueries as $sql) {
            $this->assertStringContainsString('v.entity_id BETWEEN ? AND ?', $sql);
        }
        $this->assertSame([['4', '4', 'decimal', 1, 10], ['4', '4', 'decimal', 11, 15]], $this->binds);
    }

    public function testNoOrphansIsOk(): void
    {
        $result = $this->findings(['int' => [1, 8]], ['int' => self::NONE], 'entity_id')[0];

        $this->assertSame(
            ['ok', 'No orphan EAV values (5 tables checked)'],
            [$result['severity'], $result['message']]
        );
    }

    public function testCommerceRowIdLinkFieldIsUsed(): void
    {
        $this->findings(['int' => [1, 1]], [], 'row_id');

        $orphanSql = $this->writeGuardQueries[2];
        $this->assertStringContainsString('MIN(row_id)', $this->writeGuardQueries[1]);
        $this->assertStringContainsString('e.row_id = v.row_id', $orphanSql);
        $this->assertStringContainsString('v.row_id BETWEEN ? AND ?', $orphanSql);
    }

    /**
     * @param array<string,array{int,int}> $bounds min/max link id per backend type; missing types are empty
     * @param array<string,array<string,string|null>> $perRange orphan counts returned for each range of a type
     * @param string $linkField
     * @return array<int,array<string,mixed>>
     */
    private function findings(array $bounds, array $perRange, string $linkField): array
    {
        $adapter = $this->createWriteGuardAdapter(
            function (string $sql, string $method, array $bind) use ($bounds, $perRange): mixed {
                if (str_contains($sql, 'FROM pfx_eav_entity_type')) {
                    return '4';
                }
                preg_match('~FROM pfx_catalog_product_entity_(\w+)~', $sql, $match);
                $type = $match[1] ?? throw new \LogicException($sql);
                if (str_contains($sql, 'MIN(')) {
                    return isset($bounds[$type])
                        ? ['min_id' => (string)$bounds[$type][0], 'max_id' => (string)$bounds[$type][1]]
                        : ['min_id' => null, 'max_id' => null];
                }
                $this->binds[] = $bind;
                return $perRange[$type] ?? self::NONE;
            }
        );
        $connection = new ReadOnlyConnection($this->createResourceConnection($adapter));
        $check = new EavOrphansCheck($connection, new RangeIterator($connection), $this->metadataPool($linkField), 10);

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
