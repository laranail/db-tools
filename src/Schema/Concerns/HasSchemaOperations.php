<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema\Concerns;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;

/**
 * Trait HasSchemaOperations
 *
 * Schema modification operations that check for existence before acting, so
 * each is safe to run against a database that has already been migrated.
 *
 * Every method takes an optional connection name. Passing null keeps the
 * default connection, which is what the Schema facade used to be hardcoded to:
 * a migration running against a second database read the wrong schema and wrote
 * to the wrong one, silently, whenever a table of that name existed on both.
 */
trait HasSchemaOperations
{
    /**
     * Drop columns from a table, skipping any that are not present.
     *
     * @param  array<int, string>|string  $columns
     */
    protected function dropColumnsFromTable(string $table, array|string $columns, ?string $connection = null): void
    {
        $columns = (array) $columns;
        $schema = ConnectionContext::for($connection)->schema();

        // Existence is resolved before the closure runs so the check and the
        // drop cannot disagree about which connection they mean.
        $present = array_values(array_filter(
            $columns,
            static fn (string $column): bool => $schema->hasColumn($table, $column),
        ));

        if ($present === []) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint) use ($present): void {
            foreach ($present as $column) {
                $blueprint->dropColumn($column);
            }
        });
    }

    /**
     * Add a column if it does not already exist.
     */
    protected function addColumnIfNotExists(
        string $table,
        string $column,
        Closure $definition,
        ?string $connection = null,
    ): void {
        $schema = ConnectionContext::for($connection)->schema();

        if ($schema->hasColumn($table, $column)) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint) use ($column, $definition): void {
            $definition($blueprint, $column);
        });
    }

    /**
     * Rename a column if it exists.
     */
    protected function renameColumnIfExists(
        string $table,
        string $from,
        string $to,
        ?string $connection = null,
    ): void {
        $schema = ConnectionContext::for($connection)->schema();

        if (! $schema->hasColumn($table, $from)) {
            return;
        }

        $schema->table($table, fn (Blueprint $blueprint) => $blueprint->renameColumn($from, $to));
    }

    /**
     * Drop tables if they exist.
     *
     * @param  array<int, string>|string  $tables
     */
    protected function dropTablesIfExist(string|array $tables, ?string $connection = null): void
    {
        $schema = ConnectionContext::for($connection)->schema();

        foreach ((array) $tables as $table) {
            $schema->dropIfExists($table);
        }
    }

    /**
     * Drop an index if it exists.
     *
     * The index may be given as its name (string) or the list of columns it
     * covers (array); hasIndex() and Blueprint::dropIndex() both accept either
     * form. Existence is checked first so dropping a missing index is a no-op
     * rather than a driver error.
     *
     * @param  array<int, string>|string  $index
     */
    protected function dropIndexIfExists(string $table, string|array $index, ?string $connection = null): void
    {
        $schema = ConnectionContext::for($connection)->schema();

        if (! $schema->hasTable($table) || ! $schema->hasIndex($table, $index)) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropIndex($index);
        });
    }
}
