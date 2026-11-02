<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Db;

use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;

/**
 * Reads the database server facts once per doctor run
 */
class DbServerProvider
{
    /**
     * @param ReadOnlyConnection $connection
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection
    ) {
    }

    /**
     * Database server facts for the current doctor run
     *
     * @param DoctorContext $context
     * @return DbServer
     */
    public function get(DoctorContext $context): DbServer
    {
        return $context->remember(self::class, fn (): DbServer => $this->load());
    }

    /**
     * Query the server
     *
     * @return DbServer
     */
    private function load(): DbServer
    {
        $variables = [];
        foreach ($this->connection->fetchPairs('SHOW GLOBAL VARIABLES') as $name => $value) {
            $variables[(string)$name] = (string)$value;
        }

        return new DbServer(
            (string)$this->connection->fetchOne('SELECT VERSION()'),
            $variables,
            (int)$this->connection->fetchOne(
                'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE()'
            )
        );
    }
}
