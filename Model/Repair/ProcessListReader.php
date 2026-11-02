<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Repair;

use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;

/**
 * Statements running on the database server, to tell a stuck indexer from one that is still working.
 *
 * Without the PROCESS privilege MySQL/MariaDB silently lists only the connections of the current user, so the
 * privilege is checked explicitly.
 */
class ProcessListReader
{
    /**
     * @param ReadOnlyConnection $connection
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection
    ) {
    }

    /**
     * Whether the current database user has the global PROCESS privilege
     *
     * Privileges granted through roles are not seen: the caller then treats the case as doubtful.
     *
     * @return bool
     */
    public function hasProcessPrivilege(): bool
    {
        return (int)$this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES '
            . "WHERE PRIVILEGE_TYPE = 'PROCESS' AND GRANTEE = CONCAT("
            . "'''', SUBSTRING_INDEX(CURRENT_USER(), '@', 1), '''@''', SUBSTRING_INDEX(CURRENT_USER(), '@', -1), ''''"
            . ')'
        ) > 0;
    }

    /**
     * Data-changing statements currently running on other connections
     *
     * Only writes count: storefront and admin read index tables all the time, a reindex writes them.
     *
     * @return string[]
     */
    public function getWriteStatements(): array
    {
        $statements = $this->connection->fetchCol(
            'SELECT INFO FROM information_schema.PROCESSLIST '
            . "WHERE ID <> CONNECTION_ID() AND COMMAND = 'Query' AND INFO IS NOT NULL"
        );

        return array_values(array_filter(
            array_map('strval', $statements),
            static fn (string $sql): bool => (bool)preg_match(
                '~^\s*(?:/\*.*?\*/\s*)*(?:INSERT|REPLACE|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|RENAME)\b~is',
                $sql
            )
        ));
    }
}
