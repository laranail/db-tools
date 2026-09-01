<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Exceptions;

use Simtabi\Laranail\DbTools\Services\CleanDatabaseService;

/**
 * Thrown when a truncation request is refused before anything is destroyed.
 *
 * @see CleanDatabaseService
 */
class CleanDatabaseException extends DbToolsException
{
    /**
     * A table on the protected list was named explicitly.
     *
     * Naming one is refused rather than skipped: silently ignoring part of an
     * explicit `--tables=` list would leave the caller believing it ran.
     * Truncating `migrations` in particular strands the schema at an unknown
     * version with no record of how it got there.
     */
    public static function protectedTable(string $table, string $configKey): self
    {
        return new self(
            message: "The [{$table}] table is protected and will not be truncated. "
                ."Remove it from [{$configKey}] if you really mean to.",
            code: 2201,
            context: ['table' => $table, 'config_key' => $configKey],
        );
    }

    /**
     * One or more named tables do not exist on the connection.
     *
     * @param  list<string>  $tables
     */
    public static function unknownTables(array $tables, string $connection): self
    {
        return new self(
            message: 'Unknown table(s) on connection ['.$connection.']: '.implode(', ', $tables).'.',
            code: 2202,
            context: ['tables' => $tables, 'connection' => $connection],
        );
    }

    /**
     * Truncation was asked for with nothing to truncate.
     */
    public static function nothingRequested(): self
    {
        return new self(
            message: 'No tables were given to truncate.',
            code: 2203,
            context: [],
        );
    }
}
