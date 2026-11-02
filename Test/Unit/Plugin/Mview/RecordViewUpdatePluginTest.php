<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Plugin\Mview;

use Magento\Framework\Mview\ViewInterface;
use Orangecat\IndexerBoost\Model\Config;
use Orangecat\IndexerBoost\Model\Monitor\Clock;
use Orangecat\IndexerBoost\Model\Monitor\RunRecorder;
use Orangecat\IndexerBoost\Model\Monitor\ViewIdResolver;
use Orangecat\IndexerBoost\Model\ResourceModel\RunLog;
use Orangecat\IndexerBoost\Plugin\Mview\RecordViewUpdatePlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RecordViewUpdatePluginTest extends TestCase
{
    /**
     * @var \DateTimeImmutable
     */
    private \DateTimeImmutable $startedAt;

    /**
     * @var ViewIdResolver
     */
    private ViewIdResolver $resolver;

    /**
     * @var RunRecorder
     */
    private RunRecorder $recorder;

    protected function setUp(): void
    {
        $this->startedAt = new \DateTimeImmutable('2026-10-31 08:00:00', new \DateTimeZone('UTC'));
        $resolver = $this->createStub(ViewIdResolver::class);
        $resolver->method('getIndexerId')->willReturnMap([['customer_dummy', 'customer_grid']]);
        $this->resolver = $resolver;
        $this->recorder = $this->createStub(RunRecorder::class);
    }

    public function testRecordsChangesRunUnderTheIndexerId(): void
    {
        $recorder = $this->createMock(RunRecorder::class);
        $recorder->expects($this->once())
            ->method('record')
            ->with('customer_grid', RunRecorder::KIND_CHANGES, $this->startedAt, 3.25, null);
        $this->recorder = $recorder;
        $calls = 0;

        $this->plugin()->aroundUpdate(
            $this->view('customer_dummy'),
            static function () use (&$calls): void {
                $calls++;
            }
        );

        $this->assertSame(1, $calls);
    }

    public function testFailedUpdateIsRecordedAndOriginalExceptionRethrown(): void
    {
        $error = new \RuntimeException('Lock wait timeout exceeded');
        $recorder = $this->createMock(RunRecorder::class);
        $recorder->expects($this->once())
            ->method('record')
            ->with('customer_grid', RunRecorder::KIND_CHANGES, $this->startedAt, 3.25, $this->identicalTo($error));
        $this->recorder = $recorder;

        try {
            $this->plugin()->aroundUpdate(
                $this->view('customer_dummy'),
                static function () use ($error): void {
                    throw $error;
                }
            );
            $this->fail('The update exception must be rethrown');
        } catch (\RuntimeException $caught) {
            $this->assertSame($error, $caught);
        }
    }

    public function testIndexerIdLookupFailureFallsBackToTheViewId(): void
    {
        $resolver = $this->createStub(ViewIdResolver::class);
        $resolver->method('getIndexerId')->willThrowException(new \RuntimeException('Cache backend unavailable'));
        $this->resolver = $resolver;
        $recorder = $this->createMock(RunRecorder::class);
        $recorder->expects($this->once())
            ->method('record')
            ->with('customer_dummy', RunRecorder::KIND_CHANGES, $this->startedAt, 3.25, null);
        $this->recorder = $recorder;
        $calls = 0;

        $this->plugin()->aroundUpdate(
            $this->view('customer_dummy'),
            static function () use (&$calls): void {
                $calls++;
            }
        );

        $this->assertSame(1, $calls);
    }

    #[DataProvider('durationProvider')]
    public function testOnlyUpdatesOfOneSecondOrMoreAreStored(float $end, bool $stored): void
    {
        $runLog = $this->createMock(RunLog::class);
        $runLog->expects($stored ? $this->once() : $this->never())->method('saveRun');
        $config = $this->createStub(Config::class);
        $config->method('isMonitorEnabled')->willReturn(true);
        $this->recorder = new RunRecorder($runLog, $config, $this->createStub(LoggerInterface::class), 1.0);

        $this->plugin($end)->aroundUpdate($this->view('customer_dummy'), static function (): void {
        });
    }

    /**
     * @return array<string,array{float,bool}>
     */
    public static function durationProvider(): array
    {
        return [
            'nothing to do' => [50.004, false],
            '0.99 s' => [50.99, false],
            '1 s' => [51.0, true],
        ];
    }

    /**
     * @param string $viewId
     * @return ViewInterface
     */
    private function view(string $viewId): ViewInterface
    {
        $view = $this->createStub(ViewInterface::class);
        $view->method('getId')->willReturn($viewId);
        return $view;
    }

    /**
     * Plugin whose clock starts at $this->startedAt and monotonic time 50.0
     *
     * @param float $end monotonic time when the update ends (3.25 seconds by default)
     * @return RecordViewUpdatePlugin
     */
    private function plugin(float $end = 53.25): RecordViewUpdatePlugin
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->startedAt);
        $clock->method('monotonic')->willReturnOnConsecutiveCalls(50.0, $end);

        return new RecordViewUpdatePlugin($this->resolver, $this->recorder, $clock);
    }
}
