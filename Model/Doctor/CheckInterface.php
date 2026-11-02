<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor;

/**
 * Single doctor diagnostic. Implementations must never modify data or configuration.
 *
 * @api
 */
interface CheckInterface
{
    /**
     * Unique check code, e.g. INDEXER_MODE
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Whether the check must only run from CLI
     *
     * True when it is expensive (large scans, filesystem search) or when its result is only
     * meaningful in the CLI process (e.g. environment variables).
     *
     * @return bool
     */
    public function isHeavy(): bool;

    /**
     * Run the diagnostic
     *
     * @param DoctorContext $context
     * @return CheckResult[]
     */
    public function check(DoctorContext $context): array;
}
