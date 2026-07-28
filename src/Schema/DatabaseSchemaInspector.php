<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

use Exception;
use Illuminate\Support\Facades\Log;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseSchemaInspectorInterface;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;

/**
 * Class DatabaseSchemaInspector
 *
 * Inspects database schema information.
 * Queries tables, columns, and schema details across different database drivers.
 */
class DatabaseSchemaInspector implements DatabaseSchemaInspectorInterface
{
    /**
     * Get all table names in the database
     *
     * @param  string|null  $connection  Connection name (null for default)
     * @return array List of table names
     */
    public function getTables(?string $connection = null): array
    {
        try {
            $schema = ConnectionContext::for($connection)->schema();

            return $schema->getTableListing();
        } catch (Exception $e) {
            Log::warning('Failed to get tables', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Check if a specific table exists
     *
     * @param  string  $table  Table name
     * @param  string|null  $connection  Connection name (null for default)
     * @return bool True if table exists
     */
    public function hasTable(string $table, ?string $connection = null): bool
    {
        try {
            return ConnectionContext::for($connection)->schema()->hasTable($table);
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Get total count of tables in the database
     *
     * @param  string|null  $connection  Connection name (null for default)
     * @return int Number of tables
     */
    public function getTableCount(?string $connection = null): int
    {
        try {
            $conn = ConnectionContext::for($connection)->connection();
            $driver = $conn->getDriverName();
            $database = $conn->getDatabaseName();

            $query = match ($driver) {
                'mysql', 'mariadb' => [
                    'sql' => 'SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = ?',
                    'bindings' => [$database],
                ],
                'pgsql' => [
                    'sql' => 'SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = ?',
                    'bindings' => [config('database.connections.pgsql.schema', 'public')],
                ],
                'sqlite' => [
                    'sql' => "SELECT COUNT(*) as count FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
                    'bindings' => [],
                ],
                'sqlsrv' => [
                    'sql' => "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_catalog = ? AND table_type = 'BASE TABLE'",
                    'bindings' => [$database],
                ],
                default => null,
            };

            if ($query === null) {
                return 0;
            }

            $result = $conn->select($query['sql'], $query['bindings']);

            if ($result === []) {
                return 0;
            }

            $row = $result[0];

            // Drivers may return rows as objects or associative arrays; the
            // column may also come back lower- or upper-cased depending on the
            // driver, so normalise before reading it.
            $value = is_object($row)
                ? ($row->count ?? $row->COUNT ?? null)
                : ($row['count'] ?? $row['COUNT'] ?? null);

            return (int) ($value ?? 0);
        } catch (Exception $e) {
            Log::warning('Failed to get table count', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Get all column names for a specific table
     *
     * @param  string  $table  Table name
     * @param  string|null  $connection  Connection name (null for default)
     * @return array List of column names
     */
    public function getColumns(string $table, ?string $connection = null): array
    {
        try {
            return ConnectionContext::for($connection)->schema()->getColumnListing($table);
        } catch (Exception $e) {
            Log::warning('Failed to get columns', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Check if a table has a specific column
     *
     * @param  string  $table  Table name
     * @param  string  $column  Column name
     * @param  string|null  $connection  Connection name (null for default)
     * @return bool True if column exists
     */
    public function hasColumn(string $table, string $column, ?string $connection = null): bool
    {
        try {
            return ConnectionContext::for($connection)->schema()->hasColumn($table, $column);
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Check if a table has multiple columns
     *
     * @param  string  $table  Table name
     * @param  array  $columns  Column names
     * @param  string|null  $connection  Connection name (null for default)
     * @return bool True if all columns exist
     */
    public function hasColumns(string $table, array $columns, ?string $connection = null): bool
    {
        try {
            return ConnectionContext::for($connection)->schema()->hasColumns($table, $columns);
        } catch (Exception) {
            return false;
        }
    }
}
