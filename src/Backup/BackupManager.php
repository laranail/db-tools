<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Backup;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use RuntimeException;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupDriverInterface;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupManagerInterface;
use Simtabi\Laranail\DbTools\Backup\Drivers\MysqlBackupDriver;
use Simtabi\Laranail\DbTools\Backup\Drivers\PostgresBackupDriver;
use Simtabi\Laranail\DbTools\Backup\Drivers\SqliteBackupDriver;

/**
 * Class BackupManager
 *
 * Manages database backup operations using a driver pattern.
 * Automatically registers default drivers and allows custom driver registration.
 */
class BackupManager implements BackupManagerInterface
{
    /**
     * Registered backup drivers
     *
     * @var array<BackupDriverInterface>
     */
    private array $drivers = [];

    /**
     * Initialize with default drivers
     */
    public function __construct()
    {
        $this->registerDefaultDrivers();
    }

    /**
     * Register a custom backup driver
     */
    public function registerDriver(BackupDriverInterface $driver): static
    {
        $this->drivers[] = $driver;

        return $this;
    }

    /**
     * Create a backup of the database
     *
     * @param  string  $path  Absolute path where backup should be saved
     * @param  string|null  $connection  Connection name (null for default)
     * @return bool True if backup successful
     *
     * @throws RuntimeException If backup fails
     * @throws InvalidArgumentException If configuration is invalid
     */
    public function backup(string $path, ?string $connection = null): bool
    {
        $config = $this->getConnectionConfig($connection);
        // Make the connection name available to drivers that need it (the
        // config array from database.connections.* does not carry it).
        $config['connection'] = $connection ?? Config::get('database.default');
        $driver = $this->resolveDriver($config['driver']);

        return $driver->backup($config, $path);
    }

    /**
     * Restore database from a backup file
     *
     * @param  string  $path  Absolute path to backup file
     * @param  string|null  $connection  Connection name (null for default)
     * @return bool True if restore successful
     *
     * @throws RuntimeException If restore fails
     * @throws InvalidArgumentException If file doesn't exist
     */
    public function restore(string $path, ?string $connection = null): bool
    {
        $config = $this->getConnectionConfig($connection);
        $driver = $this->resolveDriver($config['driver']);

        // Delegate to the driver so the restore mechanism matches the dump
        // format: PostgreSQL custom-format dumps restore via pg_restore (not
        // the SQL-text path), MySQL replays through the mysql client, and
        // SQLite uses SqlFileRestorer for ".sql" or a file copy otherwise.
        return $driver->restore($config, $path, $connection);
    }

    /**
     * Check if the manager supports the given database driver
     *
     * @param  string  $driver  Driver name (mysql, pgsql, sqlite, etc)
     * @return bool True if driver is supported
     */
    public function supportsDriver(string $driver): bool
    {
        return array_any($this->drivers, fn (BackupDriverInterface $backupDriver): bool => $backupDriver->supports($driver));
    }

    /**
     * Register default backup drivers
     */
    private function registerDefaultDrivers(): void
    {
        $this->registerDriver(new MysqlBackupDriver);
        $this->registerDriver(new PostgresBackupDriver);
        $this->registerDriver(new SqliteBackupDriver);
    }

    /**
     * Resolve the appropriate driver for the given database driver name
     *
     * @throws RuntimeException If no driver supports the database
     */
    private function resolveDriver(string $driverName): BackupDriverInterface
    {
        foreach ($this->drivers as $driver) {
            if ($driver->supports($driverName)) {
                return $driver;
            }
        }

        throw new RuntimeException("No backup driver supports database driver: {$driverName}");
    }

    /**
     * Get database connection configuration
     *
     * @param  string|null  $connection  Connection name (null for default)
     * @return array Connection configuration
     *
     * @throws InvalidArgumentException If connection doesn't exist
     */
    private function getConnectionConfig(?string $connection = null): array
    {
        $connectionName = $connection ?? Config::get('database.default');
        $config = Config::get("database.connections.{$connectionName}");

        if (! $config) {
            throw new InvalidArgumentException("Database connection '{$connectionName}' not found");
        }

        return $config;
    }
}
