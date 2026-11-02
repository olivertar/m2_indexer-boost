<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor;

/**
 * Severity of a doctor finding
 */
enum Severity: string
{
    case Ok = 'ok';
    case Info = 'info';
    case Warn = 'warn';
    case Critical = 'critical';

    /**
     * Numeric weight used to sort findings, higher is worse
     *
     * @return int
     */
    public function weight(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Info => 1,
            self::Warn => 2,
            self::Critical => 3,
        };
    }
}
