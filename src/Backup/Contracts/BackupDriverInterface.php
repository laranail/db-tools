<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Backup\Contracts;

use RuntimeException;

/**
 * Interface BackupDriverInterface
 *
 * Defines contract for database backup drivers.
 * Each driver handles backup operations for a specific database type.
 */
interface BackupDriverInterface
{
    /**
     * Create a backup of the database
     *
     * @param  array  $config  Database connection configuration
     * @param  string  $path  Absolute path where backup should be saved
     * @return bool True if backup successful
     *
     * @throws RuntimeException If backup fails
     */
    public function backup(array $config, string $path): bool;

    /**
     * Restore the database from a backup file produced by this driver.
     *
     * Drivers pick the correct restore mechanism for the file format they
     * produce — e.g. PostgreSQL custom-format dumps restore via pg_restore,
     * while plain ".sql" files replay through the SQL text path.
     *
     * @param  array  $config  Database connection configuration
     * @param  string  $path  Absolute path to the backup file
     * @param  string|null  $connection  Connection name (null for default)
     * @return bool True if restore successful
     *
     * @throws RuntimeException If restore fails
     */
    public function restore(array $config, string $path, ?string $connection = null): bool;

    /**
     * Check if this driver supports the given database driver
     *
     * @param  string  $driver  Driver name (mysql, pgsql, sqlite, etc)
     * @return bool True if driver is supported
     */
    public function supports(string $driver): bool;
}
