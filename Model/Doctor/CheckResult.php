<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor;

/**
 * Immutable finding produced by a doctor check
 */
class CheckResult
{
    /**
     * @param Severity $severity
     * @param string $code
     * @param string $message
     * @param string $recommendation
     * @param string $fix
     * @param array<string,mixed> $metrics
     */
    public function __construct(
        private readonly Severity $severity,
        private readonly string $code,
        private readonly string $message,
        private readonly string $recommendation = '',
        private readonly string $fix = '',
        private readonly array $metrics = []
    ) {
    }

    /**
     * Severity of the finding
     *
     * @return Severity
     */
    public function getSeverity(): Severity
    {
        return $this->severity;
    }

    /**
     * Export the finding as a plain array
     *
     * @return array{
     *     severity: string,
     *     code: string,
     *     message: string,
     *     recommendation: string,
     *     fix: string,
     *     metrics: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'code' => $this->code,
            'message' => $this->message,
            'recommendation' => $this->recommendation,
            'fix' => $this->fix,
            'metrics' => $this->metrics,
        ];
    }
}
