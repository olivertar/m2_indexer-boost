<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Product attributes with indexing-related flags but no value on any product
 */
class AttributeFlagsCheck implements CheckInterface
{
    public const CODE = 'ATTRIBUTE_FLAGS';

    /**
     * Flags of catalog_eav_attribute that make an attribute part of indexes or listings
     */
    private const FLAGS = ['is_filterable', 'used_for_sort_by', 'is_used_for_promo_rules', 'used_in_product_listing'];

    /**
     * @param ReadOnlyConnection $connection
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection
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
        return false;
    }

    /**
     * @inheritdoc
     */
    public function check(DoctorContext $context): array
    {
        $flagged = $this->connection->fetchAll(sprintf(
            'SELECT ea.attribute_id, ea.attribute_code, ea.backend_type, %1$s FROM %2$s ea '
            . 'INNER JOIN %3$s cea ON cea.attribute_id = ea.attribute_id '
            . 'INNER JOIN %4$s et ON et.entity_type_id = ea.entity_type_id '
            . "WHERE et.entity_type_code = 'catalog_product' AND ea.backend_type <> 'static' AND (%5\$s)",
            implode(', ', array_map(static fn (string $flag): string => 'cea.' . $flag, self::FLAGS)),
            $this->connection->getTableName('eav_attribute'),
            $this->connection->getTableName('catalog_eav_attribute'),
            $this->connection->getTableName('eav_entity_type'),
            implode(' OR ', array_map(static fn (string $flag): string => 'cea.' . $flag . ' > 0', self::FLAGS))
        ));

        $unused = array_fill_keys(self::FLAGS, []);
        $unusedCodes = [];
        foreach ($flagged as $attribute) {
            if ($this->hasValues((int)$attribute['attribute_id'], (string)$attribute['backend_type'])) {
                continue;
            }
            $unusedCodes[] = (string)$attribute['attribute_code'];
            foreach (self::FLAGS as $flag) {
                if ((int)$attribute[$flag] > 0) {
                    $unused[$flag][] = (string)$attribute['attribute_code'];
                }
            }
        }

        if (!$unusedCodes) {
            return [
                new CheckResult(
                    Severity::Ok,
                    self::CODE,
                    sprintf('All %d flagged attributes have product values', count($flagged))
                ),
            ];
        }

        $parts = [];
        foreach ($unused as $flag => $codes) {
            if ($codes) {
                $parts[] = $flag . ': ' . implode(', ', $codes);
            }
        }
        return [
            new CheckResult(
                Severity::Info,
                self::CODE,
                sprintf(
                    '%d flagged attribute(s) without any product value: %s',
                    count($unusedCodes),
                    implode('; ', $parts)
                ),
                'These flags pull attributes into the EAV index, product listings and rule indexing even though no '
                . 'product has a value. Review them and clear the flags that are not needed.',
                'Stores > Attributes > Product: review the listed attributes',
                ['unused' => $unused]
            ),
        ];
    }

    /**
     * Whether any product has a value for the attribute (uses the attribute_id index, stops at the first row)
     *
     * @param int $attributeId
     * @param string $backendType
     * @return bool
     */
    private function hasValues(int $attributeId, string $backendType): bool
    {
        return (bool)$this->connection->fetchOne(
            sprintf(
                'SELECT 1 FROM %s WHERE attribute_id = ? LIMIT 1',
                $this->connection->getTableName('catalog_product_entity_' . $backendType)
            ),
            [$attributeId]
        );
    }
}
