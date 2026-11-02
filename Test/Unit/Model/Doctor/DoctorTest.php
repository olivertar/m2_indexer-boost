<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor;

use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Doctor;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DoctorTest extends TestCase
{
    public function testRunReturnsFindingsOfEveryCheck(): void
    {
        $first = new CheckResult(Severity::Ok, 'FIRST', 'first ok');
        $second = new CheckResult(Severity::Ok, 'SECOND', 'second ok');
        $doctor = new Doctor(
            $this->createStub(LoggerInterface::class),
            ['FIRST' => $this->check('FIRST', [$first]), 'SECOND' => $this->check('SECOND', [$second])]
        );

        $this->assertSame([$first, $second], $doctor->run());
    }

    public function testFindingsAreSortedFromWorstToBestKeepingPoolOrderOnTies(): void
    {
        $ok = new CheckResult(Severity::Ok, 'A', 'ok');
        $warnA = new CheckResult(Severity::Warn, 'A', 'warn a');
        $info = new CheckResult(Severity::Info, 'B', 'info');
        $critical = new CheckResult(Severity::Critical, 'B', 'critical');
        $warnC = new CheckResult(Severity::Warn, 'C', 'warn c');
        $doctor = new Doctor(
            $this->createStub(LoggerInterface::class),
            [
                'A' => $this->check('A', [$ok, $warnA]),
                'B' => $this->check('B', [$info, $critical]),
                'C' => $this->check('C', [$warnC]),
            ]
        );

        $this->assertSame([$critical, $warnA, $warnC, $info, $ok], $doctor->run());
    }

    public function testOnlyRunsSelectedChecks(): void
    {
        $selected = new CheckResult(Severity::Ok, 'SECOND', 'selected');
        $notRun = $this->createMock(CheckInterface::class);
        $notRun->method('getCode')->willReturn('FIRST');
        $notRun->expects($this->never())->method('check');
        $doctor = new Doctor(
            $this->createStub(LoggerInterface::class),
            [
                'FIRST' => $notRun,
                'SECOND' => $this->check('SECOND', [$selected]),
                'THIRD' => $this->check('THIRD', [new CheckResult(Severity::Ok, 'THIRD', 'other')]),
            ]
        );

        $this->assertSame([$selected], $doctor->run(['second']));
    }

    public function testUnknownCodeInOnlyIsRejectedListingAvailableCodes(): void
    {
        $doctor = new Doctor(
            $this->createStub(LoggerInterface::class),
            ['FIRST' => $this->check('FIRST', []), 'SECOND' => $this->check('SECOND', [])]
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown check code(s): TYPO. Available: FIRST, SECOND');

        $doctor->run(['first', 'typo']);
    }

    public function testFailingCheckBecomesInfoFindingAndOthersStillRun(): void
    {
        $exception = new \RuntimeException('Access denied for SHOW GLOBAL STATUS');
        $broken = $this->createStub(CheckInterface::class);
        $broken->method('getCode')->willReturn('BROKEN');
        $broken->method('check')->willThrowException($exception);
        $ok = new CheckResult(Severity::Ok, 'FINE', 'fine');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('BROKEN'), ['exception' => $exception]);
        $doctor = new Doctor($logger, ['BROKEN' => $broken, 'FINE' => $this->check('FINE', [$ok])]);

        $results = $doctor->run();

        $this->assertCount(2, $results);
        $this->assertSame(
            [
                'severity' => 'info',
                'code' => 'BROKEN',
                'message' => 'Check could not be evaluated: Access denied for SHOW GLOBAL STATUS',
                'recommendation' => 'Check database privileges and the exception log, then run the check again.',
                'fix' => '',
                'metrics' => [],
            ],
            $results[0]->toArray()
        );
        $this->assertSame($ok, $results[1]);
    }

    public function testLightOnlySkipsHeavyChecks(): void
    {
        $light = new CheckResult(Severity::Ok, 'LIGHT', 'light');
        $heavy = $this->createMock(CheckInterface::class);
        $heavy->method('getCode')->willReturn('HEAVY');
        $heavy->method('isHeavy')->willReturn(true);
        $heavy->expects($this->never())->method('check');
        $doctor = new Doctor(
            $this->createStub(LoggerInterface::class),
            ['HEAVY' => $heavy, 'LIGHT' => $this->check('LIGHT', [$light])]
        );

        $this->assertSame([$light], $doctor->run([], true));
    }

    public function testChecksShareOneContextPerRun(): void
    {
        $seen = [];
        $recorder = function (string $code) use (&$seen): CheckInterface {
            $check = $this->createStub(CheckInterface::class);
            $check->method('getCode')->willReturn($code);
            $check->method('check')->willReturnCallback(
                static function (DoctorContext $context) use (&$seen): array {
                    $seen[] = $context;
                    return [];
                }
            );
            return $check;
        };
        $doctor = new Doctor(
            $this->createStub(LoggerInterface::class),
            ['A' => $recorder('A'), 'B' => $recorder('B')]
        );

        $doctor->run();
        $doctor->run();

        $this->assertCount(4, $seen);
        $this->assertSame($seen[0], $seen[1]);
        $this->assertSame($seen[2], $seen[3]);
        $this->assertNotSame($seen[0], $seen[2]);
    }

    /**
     * @param string $code
     * @param CheckResult[] $results
     * @param bool $heavy
     * @return CheckInterface
     */
    private function check(string $code, array $results, bool $heavy = false): CheckInterface
    {
        $check = $this->createStub(CheckInterface::class);
        $check->method('getCode')->willReturn($code);
        $check->method('isHeavy')->willReturn($heavy);
        $check->method('check')->willReturn($results);
        return $check;
    }
}
