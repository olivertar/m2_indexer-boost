<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor;

use Psr\Log\LoggerInterface;

/**
 * Runs the doctor checks
 */
class Doctor
{
    /**
     * @param LoggerInterface $logger
     * @param array<string,CheckInterface> $checks
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $checks = []
    ) {
    }

    /**
     * Run the checks and return their findings, worst first
     *
     * @param string[] $only Check codes to run, case-insensitive; empty runs all
     * @param bool $lightOnly Skip heavy checks (used by the admin page)
     * @return CheckResult[]
     */
    public function run(array $only = [], bool $lightOnly = false): array
    {
        $only = array_map('strtoupper', $only);
        $available = array_map(static fn (CheckInterface $check): string => $check->getCode(), $this->checks);
        $unknown = array_diff($only, array_map('strtoupper', $available));
        if ($unknown) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown check code(s): %s. Available: %s',
                implode(', ', $unknown),
                implode(', ', $available)
            ));
        }

        $context = new DoctorContext();
        $results = [];
        foreach ($this->checks as $check) {
            if ($only && !in_array(strtoupper($check->getCode()), $only, true)) {
                continue;
            }
            if ($lightOnly && $check->isHeavy()) {
                continue;
            }
            try {
                array_push($results, ...$check->check($context));
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Indexer Boost doctor check ' . $check->getCode() . ' failed',
                    ['exception' => $e]
                );
                $results[] = new CheckResult(
                    Severity::Info,
                    $check->getCode(),
                    'Check could not be evaluated: ' . $e->getMessage(),
                    'Check database privileges and the exception log, then run the check again.'
                );
            }
        }
        usort(
            $results,
            static fn (CheckResult $a, CheckResult $b): int =>
                $b->getSeverity()->weight() <=> $a->getSeverity()->weight()
        );
        return $results;
    }
}
