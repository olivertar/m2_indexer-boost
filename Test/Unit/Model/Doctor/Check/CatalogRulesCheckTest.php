<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Orangecat\IndexerBoost\Model\Doctor\Check\CatalogRulesCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CatalogRulesCheckTest extends TestCase
{
    use WriteGuardAdapterTrait;

    private const COMBINE = 'Magento\\CatalogRule\\Model\\Rule\\Condition\\Combine';
    private const PRODUCT = 'Magento\\CatalogRule\\Model\\Rule\\Condition\\Product';

    public function testNoActiveRulesIsOk(): void
    {
        $result = $this->findings([], '2.4.9')[0];

        $this->assertSame(['ok', 'CATALOG_RULES', 'No active catalog rules'], [
            $result['severity'],
            $result['code'],
            $result['message'],
        ]);
    }

    public function testSummaryComplexRulesAndScopedAttributes(): void
    {
        $simple = $this->conditions([['category_ids', '27,18']]);
        $complex = $this->conditions(
            array_merge([['color', '4']], array_fill(0, 10, ['sku', 'ABC']), [['special_price', '10']])
        );

        $results = $this->findings(
            [
                ['rule_id' => '1', 'name' => 'Summer', 'conditions_serialized' => $simple],
                ['rule_id' => '4', 'name' => 'B2B', 'conditions_serialized' => $complex],
            ],
            '2.4.9'
        );

        $this->assertCount(3, $results);
        $this->assertSame(
            [
                'severity' => 'info',
                'code' => 'CATALOG_RULES',
                'message' => '2 active catalog rule(s): #1 Summer (1 condition), #4 B2B (12 conditions). '
                    . 'Attributes in conditions: category_ids, color, sku, special_price',
                'recommendation' => 'Each active rule is validated against the catalog and multiplied by websites, '
                    . 'customer groups and dates in catalogrule_product_price.',
                'fix' => '',
                'metrics' => [
                    'rules' => ['1' => 1, '4' => 12],
                    'attributes' => ['category_ids', 'color', 'sku', 'special_price'],
                ],
            ],
            $results[0]
        );
        $this->assertSame('warn', $results[1]['severity']);
        $this->assertSame('Catalog rules with more than 10 conditions: #4 B2B (12 conditions)', $results[1]['message']);
        $this->assertSame('info', $results[2]['severity']);
        $this->assertSame(
            'Rule conditions use store/website-scoped attributes: color (store view), special_price (website)',
            $results[2]['message']
        );
        $this->assertNoWriteAttempts();
    }

    #[DataProvider('patchProvider')]
    public function testPatchRecommendationOnlyOn247(string $version, bool $expected): void
    {
        $results = $this->findings(
            [['rule_id' => '1', 'name' => 'Summer', 'conditions_serialized' => $this->conditions([['sku', 'A']])]],
            $version
        );

        $messages = array_column($results, 'message');
        $this->assertSame(
            $expected,
            in_array(
                'Magento 2.4.7: quality patch ACP2E-4695 fixes excessive memory use of the catalog rule indexer',
                $messages,
                true
            )
        );
    }

    /**
     * @return array<string,array{string,bool}>
     */
    public static function patchProvider(): array
    {
        return [
            '2.4.7' => ['2.4.7', true],
            '2.4.7-p3' => ['2.4.7-p3', true],
            '2.4.9' => ['2.4.9', false],
        ];
    }

    /**
     * Build a serialized condition tree with the given [attribute, value] leaves
     *
     * @param array<int,array{string,string}> $leaves
     * @return string
     */
    private function conditions(array $leaves): string
    {
        return (string)json_encode([
            'type' => self::COMBINE,
            'attribute' => null,
            'aggregator' => 'all',
            'value' => '1',
            'conditions' => [[
                'type' => self::COMBINE,
                'attribute' => null,
                'aggregator' => 'any',
                'value' => '1',
                'conditions' => array_map(
                    static fn (array $leaf): array => [
                        'type' => self::PRODUCT,
                        'attribute' => $leaf[0],
                        'operator' => '==',
                        'value' => $leaf[1],
                    ],
                    $leaves
                ),
            ]],
        ]);
    }

    /**
     * @param array<int,array<string,string>> $rules
     * @param string $magentoVersion
     * @return array<int,array<string,mixed>>
     */
    private function findings(array $rules, string $magentoVersion): array
    {
        $adapter = $this->createWriteGuardAdapter(static fn (string $sql): array => match (true) {
            str_contains($sql, 'pfx_catalogrule') => $rules,
            str_contains($sql, 'pfx_eav_attribute') => [
                'category_ids' => '1',
                'color' => '0',
                'sku' => '1',
                'special_price' => '2',
            ],
            default => throw new \LogicException($sql),
        });
        $metadata = $this->createStub(ProductMetadataInterface::class);
        $metadata->method('getVersion')->willReturn($magentoVersion);
        $check = new CatalogRulesCheck(
            new ReadOnlyConnection($this->createResourceConnection($adapter)),
            new Json(),
            $metadata,
            10
        );

        return array_map(static fn ($result): array => $result->toArray(), $check->check(new DoctorContext()));
    }
}
