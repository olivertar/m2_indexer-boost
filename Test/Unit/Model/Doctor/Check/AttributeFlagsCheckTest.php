<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\Check\AttributeFlagsCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class AttributeFlagsCheckTest extends TestCase
{
    use WriteGuardAdapterTrait;

    private const FLAGGED = [
        [
            'attribute_id' => '93', 'attribute_code' => 'color', 'backend_type' => 'int',
            'is_filterable' => '1', 'used_for_sort_by' => '0', 'is_used_for_promo_rules' => '1',
            'used_in_product_listing' => '0',
        ],
        [
            'attribute_id' => '150', 'attribute_code' => 'old_size', 'backend_type' => 'int',
            'is_filterable' => '1', 'used_for_sort_by' => '0', 'is_used_for_promo_rules' => '0',
            'used_in_product_listing' => '1',
        ],
        [
            'attribute_id' => '151', 'attribute_code' => 'legacy_note', 'backend_type' => 'text',
            'is_filterable' => '0', 'used_for_sort_by' => '0', 'is_used_for_promo_rules' => '1',
            'used_in_product_listing' => '1',
        ],
    ];

    public function testFlaggedAttributesWithoutValuesAreInfoGroupedByFlag(): void
    {
        $check = $this->check(self::FLAGGED, ['93']);

        $result = $check->check(new DoctorContext())[0]->toArray();

        $this->assertSame(
            [
                'severity' => 'info',
                'code' => 'ATTRIBUTE_FLAGS',
                'message' => '2 flagged attribute(s) without any product value: is_filterable: old_size; '
                    . 'is_used_for_promo_rules: legacy_note; used_in_product_listing: old_size, legacy_note',
                'recommendation' => 'These flags pull attributes into the EAV index, product listings and rule '
                    . 'indexing even though no product has a value. Review them and clear the flags that are not '
                    . 'needed.',
                'fix' => 'Stores > Attributes > Product: review the listed attributes',
                'metrics' => [
                    'unused' => [
                        'is_filterable' => ['old_size'],
                        'used_for_sort_by' => [],
                        'is_used_for_promo_rules' => ['legacy_note'],
                        'used_in_product_listing' => ['old_size', 'legacy_note'],
                    ],
                ],
            ],
            $result
        );
        $this->assertStringContainsString("backend_type <> 'static'", $this->writeGuardQueries[0]);
        $this->assertStringContainsString('LIMIT 1', $this->writeGuardQueries[1]);
        $this->assertNoWriteAttempts();
    }

    public function testAllFlaggedAttributesWithValuesIsOk(): void
    {
        $result = $this->check(self::FLAGGED, ['93', '150', '151'])->check(new DoctorContext())[0]->toArray();

        $this->assertSame(
            ['ok', 'All 3 flagged attributes have product values'],
            [$result['severity'], $result['message']]
        );
    }

    /**
     * @param array<int,array<string,string>> $flagged
     * @param string[] $withValues attribute ids that have at least one value
     * @return AttributeFlagsCheck
     */
    private function check(array $flagged, array $withValues): AttributeFlagsCheck
    {
        $adapter = $this->createWriteGuardAdapter(
            static function (string $sql, string $method, array $bind) use ($flagged, $withValues): mixed {
                if (str_contains($sql, 'pfx_catalog_eav_attribute')) {
                    return $flagged;
                }
                return in_array((string)($bind[0] ?? ''), $withValues, true) ? '1' : false;
            }
        );

        return new AttributeFlagsCheck(new ReadOnlyConnection($this->createResourceConnection($adapter)));
    }
}
