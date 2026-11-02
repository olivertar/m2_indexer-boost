<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Magento\Framework\App\DeploymentConfig;
use Orangecat\IndexerBoost\Model\Doctor\Check\ApplicationLockCheck;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApplicationLockCheckTest extends TestCase
{
    public function testEnabledApplicationLockIsOk(): void
    {
        $check = new ApplicationLockCheck($this->deploymentConfig(['indexer/use_application_lock' => true]));

        $results = $check->check(new DoctorContext());

        $this->assertSame('APPLICATION_LOCK', $check->getCode());
        $this->assertCount(1, $results);
        $this->assertSame(['ok', 'Indexer application lock is enabled'], [
            $results[0]->toArray()['severity'],
            $results[0]->toArray()['message'],
        ]);
    }

    /**
     * @param array<string,mixed> $values
     */
    #[DataProvider('disabledProvider')]
    public function testMissingOrDisabledApplicationLockIsWarnWithEnvPhpFix(array $values): void
    {
        $check = new ApplicationLockCheck($this->deploymentConfig($values));

        $results = $check->check(new DoctorContext());

        $this->assertCount(1, $results);
        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'APPLICATION_LOCK',
                'message' => 'indexer/use_application_lock is not enabled in app/etc/env.php',
                'recommendation' => 'Without it, a reindex that dies halfway (out of memory, killed process, '
                    . 'deployment) stays "working" forever and cron never rebuilds that index. With it, Magento '
                    . 'holds a lock while reindexing and treats a "working" indexer without lock as invalid.',
                'fix' => "app/etc/env.php: 'indexer' => ['use_application_lock' => true]",
                'metrics' => ['use_application_lock' => false],
            ],
            $results[0]->toArray()
        );
    }

    /**
     * @return array<string,array{array<string,mixed>}>
     */
    public static function disabledProvider(): array
    {
        return [
            'missing' => [[]],
            'false' => [['indexer/use_application_lock' => false]],
            'zero string' => [['indexer/use_application_lock' => '0']],
        ];
    }

    /**
     * @param array<string,mixed> $values
     * @return DeploymentConfig
     */
    private function deploymentConfig(array $values): DeploymentConfig
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(
            static fn (string $path, mixed $default = null): mixed => $values[$path] ?? $default
        );
        return $config;
    }
}
