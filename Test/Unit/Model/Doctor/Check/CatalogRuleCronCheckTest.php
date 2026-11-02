<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Magento\Cron\Model\ConfigInterface;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSize;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSizeProvider;
use Orangecat\IndexerBoost\Model\Doctor\Check\CatalogRuleCronCheck;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CatalogRuleCronCheckTest extends TestCase
{
    private const JOBS = [
        'default' => [
            'catalogrule_apply_all' => [
                'name' => 'catalogrule_apply_all',
                'instance' => 'Magento\CatalogRule\Cron\DailyCatalogUpdate',
                'method' => 'execute',
                'schedule' => '0 1 * * *',
            ],
        ],
        'index' => [
            'indexer_reindex_all_invalid' => ['name' => 'indexer_reindex_all_invalid', 'schedule' => '* * * * *'],
        ],
    ];

    public function testActiveRulesOnLargeCatalogIsWarn(): void
    {
        $check = $this->check(self::JOBS, 2, 5000);

        $results = $check->check(new DoctorContext());

        $this->assertSame('CATALOG_RULE_CRON', $check->getCode());
        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'CATALOG_RULE_CRON',
                'message' => 'catalogrule_apply_all runs at "0 1 * * *" (group default) and invalidates '
                    . 'catalogrule_product while 2 catalog rule(s) are active: a full catalog rule reindex '
                    . 'follows every run',
                'recommendation' => 'The job exists so rules with date ranges start and stop applying. Make sure '
                    . 'it runs in a low-traffic window and that the full reindex that follows fits in it.',
                'fix' => 'Move it if needed: override the catalogrule_apply_all <schedule> in a custom module '
                    . 'etc/crontab.xml',
                'metrics' => ['schedule' => '0 1 * * *', 'group' => 'default', 'active_rules' => 2],
            ],
            $results[0]->toArray()
        );
    }

    /**
     * @param array<string,array<string,array<string,string>>> $jobs
     */
    #[DataProvider('lowImpactProvider')]
    public function testLowImpactCases(
        array $jobs,
        int $activeRules,
        int $products,
        string $severity,
        string $message
    ): void {
        $result = $this->check($jobs, $activeRules, $products)->check(new DoctorContext())[0]->toArray();

        $this->assertSame($severity, $result['severity']);
        $this->assertSame($message, substr($result['message'], 0, strlen($message)));
    }

    /**
     * @return array<string,array{array<string,array<string,array<string,string>>>,int,int,string,string}>
     */
    public static function lowImpactProvider(): array
    {
        return [
            'small catalog' => [self::JOBS, 2, 4999, 'info', 'catalogrule_apply_all runs at "0 1 * * *"'],
            'no active rules' => [
                self::JOBS,
                0,
                100000,
                'ok',
                'catalogrule_apply_all is scheduled at "0 1 * * *" but no catalog rule is active: it does nothing',
            ],
            'job not scheduled' => [
                ['index' => self::JOBS['index']],
                2,
                100000,
                'ok',
                'catalogrule_apply_all is not scheduled',
            ],
        ];
    }

    /**
     * @param array<string,array<string,array<string,string>>> $jobs
     * @param int $activeRules
     * @param int $products
     * @return CatalogRuleCronCheck
     */
    private function check(array $jobs, int $activeRules, int $products): CatalogRuleCronCheck
    {
        $cronConfig = $this->createStub(ConfigInterface::class);
        $cronConfig->method('getJobs')->willReturn($jobs);
        $sizeProvider = $this->createStub(CatalogSizeProvider::class);
        $sizeProvider->method('get')->willReturn(
            new CatalogSize(['simple' => $products], 0, 1, 1, 4, $activeRules + 1, $activeRules)
        );
        return new CatalogRuleCronCheck($cronConfig, $sizeProvider, 5000);
    }
}
