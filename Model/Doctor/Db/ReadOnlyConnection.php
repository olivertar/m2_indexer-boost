<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Db;

use Magento\Framework\App\ResourceConnection;

/**
 * Read-only access to the database for doctor checks
 */
class ReadOnlyConnection
{
    /**
     * Statements allowed as first keyword
     */
    private const ALLOWED_KEYWORDS = ['SELECT', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC', 'WITH'];

    /**
     * Constructs that lock rows, write files/variables or run hidden SQL inside a read statement
     */
    private const UNSAFE_PATTERNS = [
        '~/\*!~',
        '~;\s*\S~',
        '~\bFOR\s+(?:UPDATE|SHARE)\b~i',
        '~\bLOCK\s+IN\s+SHARE\s+MODE\b~i',
        '~\bINTO\b~i',
        '~\b(?:GET_LOCK|RELEASE_LOCK|RELEASE_ALL_LOCKS)\s*\(~i',
    ];

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Resolve a table name with the configured prefix
     *
     * @param string $table
     * @return string
     */
    public function getTableName(string $table): string
    {
        return $this->resource->getTableName($table);
    }

    /**
     * Fetch all rows of a read-only statement
     *
     * @param string $sql
     * @param array<int|string,mixed> $bind
     * @return array<int,array<string,mixed>>
     */
    public function fetchAll(string $sql, array $bind = []): array
    {
        $this->assertReadOnly($sql);
        return $this->resource->getConnection()->fetchAll($sql, $bind);
    }

    /**
     * Fetch the first row of a read-only statement
     *
     * @param string $sql
     * @param array<int|string,mixed> $bind
     * @return array<string,mixed>|false
     */
    public function fetchRow(string $sql, array $bind = []): array|false
    {
        $this->assertReadOnly($sql);
        return $this->resource->getConnection()->fetchRow($sql, $bind);
    }

    /**
     * Fetch the first column of every row of a read-only statement
     *
     * @param string $sql
     * @param array<int|string,mixed> $bind
     * @return array<int,mixed>
     */
    public function fetchCol(string $sql, array $bind = []): array
    {
        $this->assertReadOnly($sql);
        return $this->resource->getConnection()->fetchCol($sql, $bind);
    }

    /**
     * Fetch first column as keys and second column as values of a read-only statement
     *
     * @param string $sql
     * @param array<int|string,mixed> $bind
     * @return array<int|string,mixed>
     */
    public function fetchPairs(string $sql, array $bind = []): array
    {
        $this->assertReadOnly($sql);
        return $this->resource->getConnection()->fetchPairs($sql, $bind);
    }

    /**
     * Fetch the first column of the first row of a read-only statement
     *
     * @param string $sql
     * @param array<int|string,mixed> $bind
     * @return mixed
     */
    public function fetchOne(string $sql, array $bind = []): mixed
    {
        $this->assertReadOnly($sql);
        return $this->resource->getConnection()->fetchOne($sql, $bind);
    }

    /**
     * Reject any statement that is not read-only
     *
     * @param string $sql
     * @return void
     * @throws WriteNotAllowedException
     */
    private function assertReadOnly(string $sql): void
    {
        $body = (string)preg_replace('~^(?:\s+|/\*.*?\*/|(?:--|#)[^\n]*(?:\n|$)|\()+~s', '', $sql);
        $keyword = strtoupper((string)strtok($body, " \t\r\n("));
        if (!in_array($keyword, self::ALLOWED_KEYWORDS, true)) {
            throw new WriteNotAllowedException('Only read-only statements are allowed: ' . $sql);
        }

        $unsafePatterns = self::UNSAFE_PATTERNS;
        if ($keyword === 'WITH') {
            $unsafePatterns[] = '~\b(?:INSERT|UPDATE|DELETE|REPLACE)\b~i';
        }
        $code = $this->stripLiterals($body);
        foreach ($unsafePatterns as $pattern) {
            if (preg_match($pattern, $code)) {
                throw new WriteNotAllowedException('Statement contains a non read-only construct: ' . $sql);
            }
        }
    }

    /**
     * Remove quoted literals and identifiers so their content is not mistaken for SQL
     *
     * @param string $sql
     * @return string
     */
    private function stripLiterals(string $sql): string
    {
        return (string)preg_replace(
            ["~'(?:[^'\\\\]|\\\\.|'')*'~s", '~"(?:[^"\\\\]|\\\\.|"")*"~s', '~`(?:[^`]|``)*`~'],
            '?',
            $sql
        );
    }
}
