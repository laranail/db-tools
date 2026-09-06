<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Backup\Contracts;

use RuntimeException;
use InvalidArgumentException;

/**
 * Interface BackupManagerInterface
 *
 * Defines contract for database backup operations.
 * Supports multiple database drivers through a driver pattern.
 */
interface BackupManagerInterface
{
    /**
     * Create a backup of the database to the specified path
     *
     * @param string $path Absolute path where backup file should be saved
     * @param string|null $connection Connection name (null for default)
     *
     * @return bool True if backup successful
     *
     * @throws RuntimeException If backup fails
     * @throws InvalidArgumentException If path is invalid
     */
    public function backup(string $path, ?string $connection = null): bool;

    /**
     * Restore database from a backup file
     *
     * @param string $path Absolute path to backup file
     * @param string|null $connection Connection name (null for default)
     *
     * @return bool True if restore successful
     *
     * @throws RuntimeException If restore fails
     * @throws InvalidArgumentException If file doesn't exist
     */
    public function restore(string $path, ?string $connection = null): bool;

    /**
     * Check if the manager supports the given database driver
     *
     * @param string $driver Driver name (mysql, pgsql, sqlite, etc)
     *
     * @return bool True if driver is supported
     */
    public function supportsDriver(string $driver): bool;
}
