<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Magento\Framework\App\ProductMetadataInterface;
use Orangecat\IndexerBoost\Model\Doctor\Check\DbServerVersionCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\DbServer;
use Orangecat\IndexerBoost\Model\Doctor\Db\DbServerProvider;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use PHPUnit\Framework\TestCase;

class DbServerVersionCheckTest extends TestCase
{
    private const MATRIX = [
        '2.4.7' => ['mysql' => ['8.0'], 'mariadb' => ['10.6']],
        '2.4.9' => ['mysql' => ['8.4'], 'mariadb' => ['11.4']],
    ];

    public function testSupportedServerIsOk(): void
    {
        $result = $this->findings('2.4.9-p1', '8.4.3')[0];

        $this->assertSame('DB_SERVER_VERSION', $result['code']);
        $this->assertSame(['ok', 'MySQL 8.4 is supported by Magento 2.4.9'], [$result['severity'], $result['message']]);
    }

    public function testUnsupportedServerIsWarn(): void
    {
        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'DB_SERVER_VERSION',
                'message' => 'MariaDB 10.11 (10.11.19-MariaDB-ubu2204) is not in the supported list for Magento '
                    . '2.4.9 (MariaDB 11.4)',
                'recommendation' => 'Unsupported database versions are not covered by Magento QA and may behave '
                    . 'differently in indexer queries (optimizer, temporary tables). The support matrix lives in '
                    . 'di.xml and can be updated without code changes.',
                'fix' => 'Plan the upgrade to MariaDB 11.4 and test it in staging with the benchmark',
                'metrics' => ['magento' => '2.4.9', 'engine' => 'mariadb', 'series' => '10.11'],
            ],
            $this->findings('2.4.9', '10.11.19-MariaDB-ubu2204')[0]
        );
    }

    public function testUnknownMagentoVersionIsInfo(): void
    {
        $result = $this->findings('2.5.0', '8.4.3')[0];

        $this->assertSame(
            ['info', 'No database support matrix for Magento 2.5.0 (server: MySQL 8.4)'],
            [$result['severity'], $result['message']]
        );
    }

    /**
     * @param string $magentoVersion
     * @param string $serverVersion
     * @return array<int,array<string,mixed>>
     */
    private function findings(string $magentoVersion, string $serverVersion): array
    {
        $metadata = $this->createStub(ProductMetadataInterface::class);
        $metadata->method('getVersion')->willReturn($magentoVersion);
        $provider = $this->createStub(DbServerProvider::class);
        $provider->method('get')->willReturn(new DbServer($serverVersion, [], 0));
        $check = new DbServerVersionCheck($provider, $metadata, self::MATRIX);

        return array_map(static fn ($result): array => $result->toArray(), $check->check(new DoctorContext()));
    }
}
