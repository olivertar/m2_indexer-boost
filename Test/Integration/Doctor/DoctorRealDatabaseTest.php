<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration\Doctor;

use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Doctor;
use Orangecat\IndexerBoost\Model\Doctor\Report\LastReportStorage;
use PHPUnit\Framework\TestCase;

/**
 * Every check of the real pool runs its SQL on the real database server through ReadOnlyConnection
 */
#[DbIsolation(true)]
class DoctorRealDatabaseTest extends TestCase
{
    /**
     * Doctor::run() turns any exception, including a rejected write, into this finding
     */
    private const NOT_EVALUATED = 'Check could not be evaluated: ';

    public function testEveryCheckIsEvaluated(): void
    {
        $results = Bootstrap::getObjectManager()->create(Doctor::class)->run();

        $codes = array_unique(array_map(
            static fn (CheckResult $result): string => $result->toArray()['code'],
            $results
        ));
        $this->assertCount(26, $codes);
        $failures = array_filter(
            array_map(static fn (CheckResult $result): array => $result->toArray(), $results),
            static fn (array $finding): bool => str_starts_with($finding['message'], self::NOT_EVALUATED)
        );
        $this->assertSame([], array_values(array_map(
            static fn (array $finding): string => $finding['code'] . ': ' . $finding['message'],
            $failures
        )));
    }

    public function testReportIsSavedAndReadBackFromVar(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $results = $objectManager->create(Doctor::class)->run(['INDEXER_STATE', 'APPLICATION_LOCK']);
        $storage = $objectManager->create(LastReportStorage::class);

        $storage->save($results);
        $report = $storage->load();

        $this->assertNotNull($report);
        $this->assertEquals($results, $report->results);
    }
}
