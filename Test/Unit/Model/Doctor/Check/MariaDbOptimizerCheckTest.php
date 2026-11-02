<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\Check\MariaDbOptimizerCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\DbServer;
use Orangecat\IndexerBoost\Model\Doctor\Db\DbServerProvider;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use PHPUnit\Framework\TestCase;

class MariaDbOptimizerCheckTest extends TestCase
{
    private const DEFAULTS = [
        'in_predicate_conversion_threshold' => '1000',
        'optimizer_use_condition_selectivity' => '4',
    ];
    private const TUNED = ['in_predicate_conversion_threshold' => '0', 'optimizer_use_condition_selectivity' => '1'];

    public function testMySqlIsNotApplicable(): void
    {
        $result = $this->findings('8.4.3', [])[0];

        $this->assertSame('MARIADB_OPTIMIZER', $result['code']);
        $this->assertSame(['ok', 'Not applicable: server is MySQL'], [$result['severity'], $result['message']]);
    }

    public function testMariaDb10WithDefaultsIsWarn(): void
    {
        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'MARIADB_OPTIMIZER',
                'message' => 'MariaDB 10.11 optimizer: in_predicate_conversion_threshold=1000, '
                    . 'optimizer_use_condition_selectivity=4',
                'recommendation' => 'On MariaDB 10.x, in_predicate_conversion_threshold turns IN() lists longer than '
                    . 'its value into a subquery, and optimizer_use_condition_selectivity=4 can pick nested loops '
                    . 'for index queries. A third party measured catalog_category_product going '
                    . 'from 3.5 h to 12 s with 100 000 products on MariaDB 10.5 by changing only these two '
                    . 'variables. Measured here on MariaDB 10.11 with 42 040 products: the root category query of '
                    . 'catalog_category_product took 400 s with optimizer_use_condition_selectivity=4 and 1.1 s with '
                    . '1; in_predicate_conversion_threshold=0 alone did not change the plan. Validate with the '
                    . 'benchmark. Both can be changed at runtime without a restart.',
                'fix' => 'SET GLOBAL in_predicate_conversion_threshold = 0; '
                    . 'SET GLOBAL optimizer_use_condition_selectivity = 1; (and persist them in my.cnf [mysqld])',
                'metrics' => self::DEFAULTS,
            ],
            $this->findings('10.11.19-MariaDB-ubu2204', self::DEFAULTS)[0]
        );
    }

    public function testMariaDb10AlreadyTunedIsOk(): void
    {
        $result = $this->findings('10.6.18-MariaDB', self::TUNED)[0];

        $this->assertSame('ok', $result['severity']);
    }

    public function testMariaDb11WithDefaultsIsInfoToTryOnACopy(): void
    {
        $result = $this->findings('11.4.4-MariaDB', self::DEFAULTS)[0];

        $this->assertSame('info', $result['severity']);
        $this->assertStringStartsWith('MariaDB 11.4 optimizer:', $result['message']);
        $this->assertStringStartsWith('MariaDB 11 changed the optimizer cost model', $result['recommendation']);
    }

    /**
     * @param string $version
     * @param array<string,string> $variables
     * @return array<int,array<string,mixed>>
     */
    private function findings(string $version, array $variables): array
    {
        $provider = $this->createStub(DbServerProvider::class);
        $provider->method('get')->willReturn(new DbServer($version, $variables, 0));
        $check = new MariaDbOptimizerCheck($provider);

        return array_map(static fn ($result): array => $result->toArray(), $check->check(new DoctorContext()));
    }
}
