<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Db\DbServerProvider;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * MariaDB optimizer variables reported to slow down index queries (unverified hypothesis, see recommendation)
 */
class MariaDbOptimizerCheck implements CheckInterface
{
    public const CODE = 'MARIADB_OPTIMIZER';

    /**
     * Variable => recommended value
     */
    private const RECOMMENDED = [
        'in_predicate_conversion_threshold' => '0',
        'optimizer_use_condition_selectivity' => '1',
    ];

    /**
     * @param DbServerProvider $dbServerProvider
     */
    public function __construct(
        private readonly DbServerProvider $dbServerProvider
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
        $server = $this->dbServerProvider->get($context);
        if (!$server->isMariaDb()) {
            return [new CheckResult(Severity::Ok, self::CODE, 'Not applicable: server is MySQL')];
        }

        $values = [];
        $tuned = true;
        foreach (array_keys(self::RECOMMENDED) as $name) {
            $values[$name] = (string)$server->getVariable($name);
            $tuned = $tuned && $values[$name] === self::RECOMMENDED[$name];
        }
        $description = [];
        foreach ($values as $name => $value) {
            $description[] = $name . '=' . $value;
        }
        $message = sprintf('MariaDB %s optimizer: %s', $server->getSeries(), implode(', ', $description));

        if ($tuned) {
            return [new CheckResult(Severity::Ok, self::CODE, $message, '', '', $values)];
        }

        $isMariaDb10 = str_starts_with($server->getSeries(), '10.');
        $fix = 'SET GLOBAL in_predicate_conversion_threshold = 0; SET GLOBAL optimizer_use_condition_selectivity = 1; '
            . '(and persist them in my.cnf [mysqld])';

        return [
            new CheckResult(
                $isMariaDb10 ? Severity::Warn : Severity::Info,
                self::CODE,
                $message,
                $isMariaDb10
                    ? 'On MariaDB 10.x, in_predicate_conversion_threshold turns IN() lists longer than its value '
                    . 'into a subquery, and optimizer_use_condition_selectivity=4 can pick nested loops for index '
                    . 'queries. A third party measured catalog_category_product going from 3.5 h '
                    . 'to 12 s with 100 000 products on MariaDB 10.5 by changing only these two variables. '
                    . 'Measured here on MariaDB 10.11 with 42 040 products: the root category query of '
                    . 'catalog_category_product took 400 s with optimizer_use_condition_selectivity=4 and 1.1 s with '
                    . '1; in_predicate_conversion_threshold=0 alone did not change the plan. Validate with the '
                    . 'benchmark. Both can be changed at runtime without a restart.'
                    : 'MariaDB 11 changed the optimizer cost model, so the gains reported for 10.x may not apply. '
                    . 'Try these values on a copy of the database and compare with the benchmark.',
                $fix,
                $values
            ),
        ];
    }
}
