<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Active catalog rules, conditions per rule and attribute scopes used in conditions
 */
class CatalogRulesCheck implements CheckInterface
{
    public const CODE = 'CATALOG_RULES';

    /**
     * catalog_eav_attribute.is_global => label
     */
    private const SCOPES = ['0' => 'store view', '2' => 'website'];

    /**
     * @param ReadOnlyConnection $connection
     * @param Json $json
     * @param ProductMetadataInterface $productMetadata
     * @param int $maxConditionsPerRule
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection,
        private readonly Json $json,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly int $maxConditionsPerRule = 10
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
        $rules = $this->connection->fetchAll(sprintf(
            'SELECT rule_id, name, conditions_serialized FROM %s WHERE is_active = 1',
            $this->connection->getTableName('catalogrule')
        ));
        if (!$rules) {
            return [new CheckResult(Severity::Ok, self::CODE, 'No active catalog rules')];
        }

        $conditionCounts = [];
        $descriptions = [];
        $complex = [];
        $attributes = [];
        foreach ($rules as $rule) {
            $ruleAttributes = $this->collectAttributes((string)$rule['conditions_serialized']);
            $count = count($ruleAttributes);
            $label = sprintf(
                '#%d %s (%d %s)',
                $rule['rule_id'],
                $rule['name'],
                $count,
                $count === 1 ? 'condition' : 'conditions'
            );
            $conditionCounts[(string)$rule['rule_id']] = $count;
            $descriptions[] = $label;
            if ($count > $this->maxConditionsPerRule) {
                $complex[] = $label;
            }
            $attributes[] = $ruleAttributes;
        }
        $attributes = array_values(array_unique(array_merge([], ...$attributes)));
        sort($attributes);

        $results = [
            new CheckResult(
                Severity::Info,
                self::CODE,
                sprintf(
                    '%d active catalog rule(s): %s. Attributes in conditions: %s',
                    count($rules),
                    implode(', ', $descriptions),
                    implode(', ', $attributes)
                ),
                'Each active rule is validated against the catalog and multiplied by websites, customer groups and '
                . 'dates in catalogrule_product_price.',
                '',
                ['rules' => $conditionCounts, 'attributes' => $attributes]
            ),
        ];

        if ($complex) {
            $results[] = new CheckResult(
                Severity::Warn,
                self::CODE,
                sprintf(
                    'Catalog rules with more than %d conditions: %s',
                    $this->maxConditionsPerRule,
                    implode(', ', $complex)
                ),
                'Conditions are validated product by product in PHP during the catalog rule reindex; long '
                . 'condition trees multiply that cost. Split or simplify them (e.g. one category condition instead '
                . 'of many SKUs).',
                'Marketing > Catalog Price Rules: simplify the listed rules'
            );
        }

        $scoped = $this->describeScopedAttributes($attributes);
        if ($scoped) {
            $results[] = new CheckResult(
                Severity::Info,
                self::CODE,
                'Rule conditions use store/website-scoped attributes: ' . implode(', ', $scoped),
                'Scoped attribute values must be resolved per store or website for every product, which makes rule '
                . 'validation slower than with global attributes.',
                'Prefer global attributes in rule conditions where the business allows it'
            );
        }

        if (str_starts_with((string)$this->productMetadata->getVersion(), '2.4.7')) {
            $results[] = new CheckResult(
                Severity::Warn,
                self::CODE,
                'Magento 2.4.7: quality patch ACP2E-4695 fixes excessive memory use of the catalog rule indexer',
                'Without the patch the catalog rule reindex can run out of memory on large catalogs.',
                'vendor/bin/magento-patches apply ACP2E-4695'
            );
        }

        return $results;
    }

    /**
     * Attribute codes of every leaf condition, one entry per condition
     *
     * @param string $serialized
     * @return string[]
     */
    private function collectAttributes(string $serialized): array
    {
        if ($serialized === '') {
            return [];
        }
        try {
            $tree = $this->json->unserialize($serialized);
        } catch (\InvalidArgumentException) {
            return [];
        }
        return is_array($tree) ? $this->walk($tree) : [];
    }

    /**
     * Recursively collect attribute codes of a condition node
     *
     * @param array<mixed> $node
     * @return string[]
     */
    private function walk(array $node): array
    {
        $attributes = [[]];
        if (!empty($node['attribute']) && is_string($node['attribute'])) {
            $attributes[] = [$node['attribute']];
        }
        foreach ((array)($node['conditions'] ?? []) as $child) {
            if (is_array($child)) {
                $attributes[] = $this->walk($child);
            }
        }
        return array_merge(...$attributes);
    }

    /**
     * Describe attributes that are not global, e.g. "color (store view)"
     *
     * @param string[] $attributes
     * @return string[]
     */
    private function describeScopedAttributes(array $attributes): array
    {
        if (!$attributes) {
            return [];
        }
        $scopes = $this->connection->fetchPairs(
            sprintf(
                'SELECT ea.attribute_code, cea.is_global FROM %s ea '
                . 'INNER JOIN %s cea ON cea.attribute_id = ea.attribute_id '
                . 'INNER JOIN %s et ON et.entity_type_id = ea.entity_type_id '
                . "WHERE et.entity_type_code = 'catalog_product' AND ea.attribute_code IN (%s)",
                $this->connection->getTableName('eav_attribute'),
                $this->connection->getTableName('catalog_eav_attribute'),
                $this->connection->getTableName('eav_entity_type'),
                implode(', ', array_fill(0, count($attributes), '?'))
            ),
            $attributes
        );

        $scoped = [];
        foreach ($attributes as $code) {
            $scope = (string)($scopes[$code] ?? '1');
            if (isset(self::SCOPES[$scope])) {
                $scoped[] = sprintf('%s (%s)', $code, self::SCOPES[$scope]);
            }
        }
        return $scoped;
    }
}
