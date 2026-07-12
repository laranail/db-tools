<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Services\Contracts;

/**
 * Maintenance Service Interface
 *
 * Application housekeeping that operates on the filesystem rather than the
 * database: clearing caches, log files, and the public storage symlink. Kept
 * separate from DatabaseServiceInterface so the database surface stays focused.
 */
interface MaintenanceServiceInterface
{
    /**
     * Flush the cache and delete framework/bootstrap cache files.
     *
     * @return bool True on success, false on failure
     */
    public function clearCache(): bool;

    /**
     * Clear log files (logs, clockwork, debugbar), preserving .gitignore.
     *
     * @return bool True on success, false on failure
     */
    public function clearLogFiles(): bool;

    /**
     * Delete the public storage symlink.
     *
     * @return bool True on success, false on failure
     */
    public function deleteStorageSymlink(): bool;
}
