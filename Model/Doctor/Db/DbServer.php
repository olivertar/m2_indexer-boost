<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Db;

/**
 * Database server engine, version, global variables and database size
 */
class DbServer
{
    /**
     * @param string $version
     * @param array<string,string> $variables
     * @param int $databaseSize Data + index bytes of the Magento schema
     */
    public function __construct(
        private readonly string $version,
        private readonly array $variables,
        private readonly int $databaseSize
    ) {
    }

    /**
     * Full version string, e.g. 10.11.19-MariaDB-ubu2204
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Whether the server is MariaDB
     *
     * @return bool
     */
    public function isMariaDb(): bool
    {
        return stripos($this->version, 'mariadb') !== false;
    }

    /**
     * Major.minor series, e.g. 10.11 or 8.4
     *
     * @return string
     */
    public function getSeries(): string
    {
        return preg_match('/^(\d+)\.(\d+)/', $this->version, $matches) ? $matches[1] . '.' . $matches[2] : '';
    }

    /**
     * Engine name for messages
     *
     * @return string
     */
    public function getEngine(): string
    {
        return $this->isMariaDb() ? 'MariaDB' : 'MySQL';
    }

    /**
     * Global variable value, null when the server does not have it
     *
     * @param string $name
     * @return string|null
     */
    public function getVariable(string $name): ?string
    {
        return $this->variables[$name] ?? null;
    }

    /**
     * Data + index bytes of the Magento schema
     *
     * @return int
     */
    public function getDatabaseSize(): int
    {
        return $this->databaseSize;
    }
}
