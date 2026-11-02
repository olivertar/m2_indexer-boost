<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Orangecat\IndexerBoost\Model\ResourceModel\RunLog;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RunLogTest extends TestCase
{
    /**
     * @var AdapterInterface&MockObject
     */
    private AdapterInterface $adapter;

    /**
     * @var RunLog
     */
    private RunLog $runLog;

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(AdapterInterface::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->adapter);
        $resource->method('getTableName')->willReturnCallback(static fn ($name): string => 'pfx_' . $name);
        $context = $this->createStub(Context::class);
        $context->method('getResources')->willReturn($resource);
        $this->runLog = new RunLog($context);
    }

    public function testSaveRunStoresUtcStartAndRoundedDuration(): void
    {
        $this->adapter->expects($this->once())
            ->method('insert')
            ->with('pfx_orangecat_indexer_boost_run_log', [
                'indexer_id' => 'catalog_product_price',
                'kind' => 'full',
                'started_at' => '2026-10-31 08:15:00',
                'duration_s' => '125.457',
                'failed' => 1,
                'error' => 'RuntimeException: Lock wait timeout',
            ]);

        $this->runLog->saveRun(
            'catalog_product_price',
            'full',
            new \DateTimeImmutable('2026-10-31 09:15:00', new \DateTimeZone('Europe/Madrid')),
            125.4567,
            'RuntimeException: Lock wait timeout'
        );
    }

    public function testSaveSuccessfulRunWithoutError(): void
    {
        $this->adapter->expects($this->once())
            ->method('insert')
            ->with($this->anything(), $this->callback(
                static fn (array $row): bool => $row['failed'] === 0 && $row['error'] === null
            ));

        $this->runLog->saveRun('catalogsearch_fulltext', 'changes', new \DateTimeImmutable('now'), 1.5, null);
    }

    public function testDeleteOlderThanUsesTheStartedAtIndexInUtc(): void
    {
        $this->adapter->expects($this->once())
            ->method('delete')
            ->with('pfx_orangecat_indexer_boost_run_log', ['started_at < ?' => '2026-08-02 22:00:00'])
            ->willReturn(42);

        $this->assertSame(
            42,
            $this->runLog->deleteOlderThan(
                new \DateTimeImmutable('2026-08-03 00:00:00', new \DateTimeZone('Europe/Madrid'))
            )
        );
    }
}
