<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Db;

use Orangecat\IndexerBoost\Model\Doctor\Db\DbServerProvider;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DbServerProviderTest extends TestCase
{
    use WriteGuardAdapterTrait;

    #[DataProvider('versionProvider')]
    public function testDetectsEngineVersionAndVariables(
        string $version,
        bool $isMariaDb,
        string $series
    ): void {
        $adapter = $this->createWriteGuardAdapter(static fn (string $sql): mixed => match (true) {
            str_contains($sql, 'VERSION()') => $version,
            str_contains($sql, 'SHOW GLOBAL VARIABLES') => ['innodb_buffer_pool_size' => '134217728'],
            str_contains($sql, 'information_schema.TABLES') => '52428800',
            default => throw new \LogicException($sql),
        });
        $provider = new DbServerProvider(new ReadOnlyConnection($this->createResourceConnection($adapter)));
        $context = new DoctorContext();

        $server = $provider->get($context);

        $this->assertSame($isMariaDb, $server->isMariaDb());
        $this->assertSame($version, $server->getVersion());
        $this->assertSame($series, $server->getSeries());
        $this->assertSame('134217728', $server->getVariable('innodb_buffer_pool_size'));
        $this->assertNull($server->getVariable('temptable_max_ram'));
        $this->assertSame(52428800, $server->getDatabaseSize());
        $this->assertSame($server, $provider->get($context));
        $this->assertNoWriteAttempts();
    }

    /**
     * @return array<string,array{string,bool,string}>
     */
    public static function versionProvider(): array
    {
        return [
            'mariadb' => ['10.11.19-MariaDB-ubu2204', true, '10.11'],
            'mysql' => ['8.4.3', false, '8.4'],
            'mysql with suffix' => ['8.0.39-0ubuntu0.22.04.1', false, '8.0'],
        ];
    }
}
