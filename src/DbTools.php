<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools;

use Closure;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupManagerInterface;
use Simtabi\Laranail\DbTools\Guard\Contracts\DatabaseAvailabilityInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseConnectionTesterInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseSchemaInspectorInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseTableVerifierInterface;

/**
 * Database Facade
 *
 * Unified entry point for all database utilities.
 * Provides access to connection testing, schema inspection, table
 * verification, and backup operations. (Seeding lives in
 * laranail/package-tools.)
 *
 * Usage:
 * ```php
 * use Simtabi\Laranail\DbTools\DbTools;
 *
 * // Connection testing
 * DbTools::testConnection();
 * DbTools::getConnectionInfo();
 *
 * // Schema inspection
 * DbTools::tables();
 * DbTools::hasTable('users');
 *
 * // Backup
 * DbTools::backup('/path/to/dump.sql');
 * ```
 */
class DbTools
{
    // =========================================================================
    // Connection Testing
    // =========================================================================

    /**
     * Test if database connection is working
     *
     * @param  string|null  $connection  Connection name (null for default)
     */
    public static function testConnection(?string $connection = null): bool
    {
        return static::connectionTester()->test($connection);
    }

    /**
     * Get detailed connection information
     *
     * @param  string|null  $connection  Connection name (null for default)
     */
    public static function getConnectionInfo(?string $connection = null): array
    {
        return static::connectionTester()->testDetailed($connection);
    }

    /**
     * Get the database driver name
     *
     * @param  string|null  $connection  Connection name (null for default)
     */
    public static function getDriver(?string $connection = null): string
    {
        return static::connectionTester()->getDriver($connection);
    }

    /**
     * Get the database server version
     *
     * @param  string|null  $connection  Connection name (null for default)
     */
    public static function getVersion(?string $connection = null): ?string
    {
        return static::connectionTester()->getVersion($connection);
    }

    /**
     * Get the database name
     *
     * @param  string|null  $connection  Connection name (null for default)
     */
    public static function getDatabaseName(?string $connection = null): ?string
    {
        return static::connectionTester()->getDatabaseName($connection);
    }

    // =========================================================================
    // Schema Inspection
    // =========================================================================

    /**
     * Get all table names
     *
     * @param  string|null  $connection  Connection name (null for default)
     */
    public static function tables(?string $connection = null): array
    {
        return static::schemaInspector()->getTables($connection);
    }

    /**
     * Check if a table exists
     *
     * @param  string  $table  Table name
     * @param  string|null  $connection  Connection name (null for default)
     */
    public static function hasTable(string $table, ?string $connection = null): bool
    {
        return static::schemaInspector()->hasTable($table, $connection);
    }

    /**
     * Get the table count
     *
     * @param  string|null  $connection  Connection name (null for default)
     */
    public static function tableCount(?string $connection = null): int
    {
        return static::schemaInspector()->getTableCount($connection);
    }

    /**
     * Get column names for a table
     *
     * @param  string  $table  Table name
     * @param  string|null  $connection  Connection name (null for default)
     */
    public static function columns(string $table, ?string $connection = null): array
    {
        return static::schemaInspector()->getColumns($table, $connection);
    }

    /**
     * Check if a table has a column
     *
     * @param  string  $table  Table name
     * @param  string  $column  Column name
     * @param  string|null  $connection  Connection name (null for default)
     */
    public static function hasColumn(string $table, string $column, ?string $connection = null): bool
    {
        return static::schemaInspector()->hasColumn($table, $column, $connection);
    }

    // =========================================================================
    // Table Verification
    // =========================================================================

    /**
     * Verify tables exist
     *
     * @param  array  $tables  Table names to check
     * @param  bool  $requireAll  Whether all tables must exist
     * @param  string|null  $connection  Connection name (null for default)
     */
    public static function verifyTables(array $tables, bool $requireAll = false, ?string $connection = null): bool
    {
        return static::tableVerifier()->verify($tables, $requireAll, $connection);
    }

    /**
     * Verify tables with detailed results
     *
     * @param  array  $tables  Table names to check
     * @param  bool  $requireAll  Whether all tables must exist
     * @param  string|null  $connection  Connection name (null for default)
     */
    public static function verifyTablesDetailed(array $tables, bool $requireAll = false, ?string $connection = null): array
    {
        return static::tableVerifier()->verifyDetailed($tables, $requireAll, true, $connection);
    }

    /**
     * Check if Laravel default tables exist
     *
     * @param  bool  $strict  Whether all tables must exist
     * @param  string|null  $connection  Connection name (null for default)
     */
    public static function hasLaravelTables(bool $strict = false, ?string $connection = null): bool
    {
        return static::tableVerifier()->hasLaravelTables($strict, $connection);
    }

    /**
     * Get missing tables from a list
     *
     * @param  array  $tables  Table names to check
     * @param  string|null  $connection  Connection name (null for default)
     */
    public static function getMissingTables(array $tables, ?string $connection = null): array
    {
        return static::tableVerifier()->getMissingTables($tables, $connection);
    }

    // =========================================================================
    // Backup Operations
    // =========================================================================

    /**
     * Create a database backup
     *
     * @param  string  $path  Path to save backup
     * @param  string|null  $connection  Connection name (null for default)
     */
    public static function backup(string $path, ?string $connection = null): bool
    {
        return static::backupManager()->backup($path, $connection);
    }

    /**
     * Restore database from backup
     *
     * @param  string  $path  Path to backup file
     * @param  string|null  $connection  Connection name (null for default)
     */
    public static function restore(string $path, ?string $connection = null): bool
    {
        return static::backupManager()->restore($path, $connection);
    }

    /**
     * Check if backup driver is supported
     *
     * @param  string  $driver  Driver name
     */
    public static function supportsBackupDriver(string $driver): bool
    {
        return static::backupManager()->supportsDriver($driver);
    }

    // =========================================================================
    // Foreign Keys
    // =========================================================================

    /**
     * Run a callback with foreign key constraints disabled (driver-aware).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutForeignKeyChecks(Closure $callback): mixed
    {
        return Schema::withoutForeignKeyConstraints($callback);
    }

    // =========================================================================
    // Service Accessors
    // =========================================================================

    /**
     * Get the boot-safe availability guard instance.
     */
    public static function guard(): DatabaseAvailabilityInterface
    {
        return app(DatabaseAvailabilityInterface::class);
    }

    /**
     * True when the connection (or default) is reachable — never throws.
     */
    public static function isAvailable(?string $connection = null): bool
    {
        return self::guard()->isAvailable($connection);
    }

    /**
     * True when the connection is reachable AND the table exists — never throws.
     */
    public static function tableExists(string $table, ?string $connection = null): bool
    {
        return self::guard()->hasTable($table, $connection);
    }

    /**
     * Run $callback only when the database is available; otherwise return $default.
     *
     * @template TValue
     *
     * @param  callable():TValue  $callback
     * @param  TValue  $default
     * @return TValue
     */
    public static function whenAvailable(callable $callback, mixed $default = null, ?string $connection = null): mixed
    {
        return self::guard()->whenAvailable($callback, $default, $connection);
    }

    /**
     * Get connection tester instance
     */
    public static function connectionTester(): DatabaseConnectionTesterInterface
    {
        return app(DatabaseConnectionTesterInterface::class);
    }

    /**
     * Get schema inspector instance
     */
    public static function schemaInspector(): DatabaseSchemaInspectorInterface
    {
        return app(DatabaseSchemaInspectorInterface::class);
    }

    /**
     * Get table verifier instance
     */
    public static function tableVerifier(): DatabaseTableVerifierInterface
    {
        return app(DatabaseTableVerifierInterface::class);
    }

    /**
     * Get backup manager instance
     */
    public static function backupManager(): BackupManagerInterface
    {
        return app(BackupManagerInterface::class);
    }
}
