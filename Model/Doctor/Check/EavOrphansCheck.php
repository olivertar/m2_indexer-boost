<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Db\RangeIterator;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Product EAV values whose product, attribute or store no longer exists, or stored in the wrong backend table.
 *
 * Counts only, by ranges of the link field of each value table.
 */
class EavOrphansCheck implements CheckInterface
{
    public const CODE = 'EAV_ORPHANS';

    private const TYPES = ['int', 'varchar', 'decimal', 'datetime', 'text'];

    /**
     * Orphan kind => label
     */
    private const KINDS = [
        'missing_entity' => 'missing product',
        'foreign_attribute' => 'unknown or non-product attribute',
        'wrong_backend' => 'attribute stored in another backend table',
        'missing_store' => 'missing store',
    ];

    /**
     * @param ReadOnlyConnection $connection
     * @param RangeIterator $rangeIterator
     * @param MetadataPool $metadataPool
     * @param int $rangeSize
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection,
        private readonly RangeIterator $rangeIterator,
        private readonly MetadataPool $metadataPool,
        private readonly int $rangeSize = 10000
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * @inheritdoc
     */
    public function isHeavy(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function check(DoctorContext $context): array
    {
        $entityTypeId = (string)$this->connection->fetchOne(sprintf(
            "SELECT entity_type_id FROM %s WHERE entity_type_code = 'catalog_product'",
            $this->connection->getTableName('eav_entity_type')
        ));
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();

        $orphans = [];
        foreach (self::TYPES as $type) {
            $name = 'catalog_product_entity_' . $type;
            $counts = array_filter($this->countOrphans($type, $linkField, $entityTypeId));
            if ($counts) {
                $orphans[$name] = $counts;
            }
        }

        if (!$orphans) {
            return [
                new CheckResult(
                    Severity::Ok,
                    self::CODE,
                    sprintf('No orphan EAV values (%d tables checked)', count(self::TYPES))
                ),
            ];
        }

        $total = 0;
        $parts = [];
        foreach ($orphans as $name => $counts) {
            $total += array_sum($counts);
            $kinds = [];
            foreach ($counts as $kind => $count) {
                $kinds[] = $count . ' ' . self::KINDS[$kind];
            }
            $parts[] = sprintf('%s (%s)', $name, implode(', ', $kinds));
        }
        return [
            new CheckResult(
                Severity::Warn,
                self::CODE,
                sprintf('%d orphan EAV values: %s', $total, implode(', ', $parts)),
                'No indexer or storefront page reads these rows, but EAV queries still scan them. Missing products '
                . 'or stores mean foreign keys were dropped (for example by a partial import or a dump restored '
                . 'without them). Values in another backend table are left behind when an attribute backend_type '
                . 'is changed.',
                'Delete the orphan rows on staging, by entity ranges (the doctor only counts, it never deletes); '
                . 'restore missing foreign keys with bin/magento setup:upgrade',
                ['orphans' => $orphans, 'range_size' => $this->rangeSize]
            ),
        ];
    }

    /**
     * Count orphans of one EAV table per kind, one link-field range at a time
     *
     * @param string $type
     * @param string $linkField
     * @param string $entityTypeId
     * @return array<string,int>
     */
    private function countOrphans(string $type, string $linkField, string $entityTypeId): array
    {
        $table = $this->connection->getTableName('catalog_product_entity_' . $type);
        $sql = sprintf(
            'SELECT SUM(e.%2$s IS NULL) AS missing_entity, '
            . 'SUM(ea.attribute_id IS NULL OR ea.entity_type_id <> ?) AS foreign_attribute, '
            . 'SUM(ea.entity_type_id = ? AND ea.backend_type <> ?) AS wrong_backend, '
            . 'SUM(st.store_id IS NULL) AS missing_store '
            . 'FROM %1$s v '
            . 'LEFT JOIN %3$s e ON e.%2$s = v.%2$s '
            . 'LEFT JOIN %4$s ea ON ea.attribute_id = v.attribute_id '
            . 'LEFT JOIN %5$s st ON st.store_id = v.store_id '
            . 'WHERE v.%2$s BETWEEN ? AND ?',
            $table,
            $linkField,
            $this->connection->getTableName('catalog_product_entity'),
            $this->connection->getTableName('eav_attribute'),
            $this->connection->getTableName('store')
        );

        $counts = array_fill_keys(array_keys(self::KINDS), 0);
        foreach ($this->rangeIterator->ranges($table, $linkField, $this->rangeSize) as [$from, $to]) {
            $row = $this->connection->fetchRow($sql, [$entityTypeId, $entityTypeId, $type, $from, $to]) ?: [];
            foreach (array_keys($counts) as $kind) {
                $counts[$kind] += (int)($row[$kind] ?? 0);
            }
        }
        return $counts;
    }
}
