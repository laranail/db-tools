<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

use Exception;
use Illuminate\Support\Facades\Log;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseSchemaInspectorInterface;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;
use Throwable;

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
        $context = ConnectionContext::for($connection);

        try {
            return $context->schema()->hasTable($table);
        } catch (Exception $e) {
            // "No such table" and "cannot reach this database" both landed
            // here and both answered false, so verifying against a database
            // that was down reported every table missing — which sends the
            // operator to run migrations when the connection is the problem.
            if (! $this->isReachable($context)) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * Whether the connection can actually be opened.
     */
    private function isReachable(ConnectionContext $context): bool
    {
        try {
            $context->connection()->getPdo();

            return true;
        } catch (Throwable) {
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
            $context = ConnectionContext::for($connection);
            $conn = $context->connection();
            $driver = $conn->getDriverName();
            $database = $conn->getDatabaseName();

            $query = match ($driver) {
                // table_type filters out views, which getTables() also excludes
                // (it uses getTableListing()). Without it the two disagreed on any
                // schema containing a view.
                'mysql', 'mariadb' => [
                    'sql' => "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'",
                    'bindings' => [$database],
                ],
                // Read the schema off the connection being counted. Hardcoding
                // `database.connections.pgsql.schema` meant counting a different
                // connection's schema — or, with no connection literally named
                // "pgsql", silently falling back to "public".
                'pgsql' => [
                    'sql' => "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'",
                    'bindings' => [$this->postgresSchema($context)],
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
        $context = ConnectionContext::for($connection);

        try {
            return $context->schema()->hasColumn($table, $column);
        } catch (Exception $e) {
            if (! $this->isReachable($context)) {
                throw $e;
            }

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
        $context = ConnectionContext::for($connection);

        try {
            return $context->schema()->hasColumns($table, $columns);
        } catch (Exception $e) {
            if (! $this->isReachable($context)) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * The schema a PostgreSQL connection reads from.
     *
     * `search_path` may be a comma-separated list or an array; the first entry is
     * the one unqualified lookups resolve against.
     */
    private function postgresSchema(ConnectionContext $context): string
    {
        $searchPath = $context->config('search_path') ?? $context->config('schema');

        if (is_array($searchPath)) {
            $searchPath = $searchPath[0] ?? null;
        }

        if (! is_string($searchPath) || trim($searchPath) === '') {
            return 'public';
        }

        $first = trim(explode(',', $searchPath)[0]);

        return $first === '' ? 'public' : $first;
    }
}
