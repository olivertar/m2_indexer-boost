<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor;

/**
 * Data shared between the checks of a single doctor run
 */
class DoctorContext
{
    /**
     * @var array<string,mixed>
     */
    private array $values = [];

    /**
     * Return the value stored under $key, computing it with $loader on first access
     *
     * @param string $key
     * @param callable $loader Returns the value to store
     * @return mixed
     */
    public function remember(string $key, callable $loader): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            $this->values[$key] = $loader();
        }
        return $this->values[$key];
    }
}
