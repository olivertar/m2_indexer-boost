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
 * Product EAV values of a store view equal to the default (store_id 0) value.
 *
 * Counts only, by ranges of the link field, so large EAV tables are never scanned in one query.
 */
class EavRedundantStoreValuesCheck implements CheckInterface
{
    public const CODE = 'EAV_REDUNDANT_STORE_VALUES';

    /**
     * Backend type => whether values must be compared byte by byte (case-insensitive collations)
     */
    private const TYPES = ['int' => false, 'varchar' => true, 'decimal' => false, 'datetime' => false, 'text' => true];

    /**
     * @param ReadOnlyConnection $connection
     * @param RangeIterator $rangeIterator
     * @param MetadataPool $metadataPool
     * @param int $rangeSize
     * @param int $warnRows
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection,
        private readonly RangeIterator $rangeIterator,
        private readonly MetadataPool $metadataPool,
        private readonly int $rangeSize = 10000,
        private readonly int $warnRows = 100000
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
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $redundant = [];
        foreach (self::TYPES as $type => $binary) {
            $name = 'catalog_product_entity_' . $type;
            $count = $this->countRedundant($this->connection->getTableName($name), $linkField, $binary);
            if ($count > 0) {
                $redundant[$name] = $count;
            }
        }

        if (!$redundant) {
            return [
                new CheckResult(
                    Severity::Ok,
                    self::CODE,
                    sprintf(
                        'No store-view EAV values equal to the default value (%d tables checked)',
                        count(self::TYPES)
                    )
                ),
            ];
        }

        $total = array_sum($redundant);
        $parts = [];
        foreach ($redundant as $name => $count) {
            $parts[] = sprintf('%s (%d)', $name, $count);
        }
        return [
            new CheckResult(
                $total >= $this->warnRows ? Severity::Warn : Severity::Info,
                self::CODE,
                sprintf('%d store-view EAV values equal to the default value: %s', $total, implode(', ', $parts)),
                'These rows add work to every EAV join of the indexers without changing any value. They usually '
                . 'come from imports or from saving a store view without "Use Default Value". Deleting them makes '
                . 'those store views follow the default value, including future changes: confirm with the business '
                . 'and clean them on staging first.',
                'Delete the store-view rows equal to store_id 0 on staging, by entity ranges (the doctor only '
                . 'counts, it never deletes)',
                ['redundant' => $redundant, 'range_size' => $this->rangeSize]
            ),
        ];
    }

    /**
     * Count redundant store-view rows of one EAV table, one link-field range at a time
     *
     * @param string $table
     * @param string $linkField
     * @param bool $binary
     * @return int
     */
    private function countRedundant(string $table, string $linkField, bool $binary): int
    {
        $sql = sprintf(
            'SELECT COUNT(*) FROM %1$s s INNER JOIN %1$s d ON d.%2$s = s.%2$s AND d.attribute_id = s.attribute_id '
            . 'AND d.store_id = 0 WHERE s.%2$s BETWEEN ? AND ? AND s.store_id > 0 AND %3$s',
            $table,
            $linkField,
            $binary ? 'BINARY s.value <=> BINARY d.value' : 's.value <=> d.value'
        );
        $count = 0;
        foreach ($this->rangeIterator->ranges($table, $linkField, $this->rangeSize) as $range) {
            $count += (int)$this->connection->fetchOne($sql, $range);
        }
        return $count;
    }
}
