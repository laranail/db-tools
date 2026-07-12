<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema\Concerns;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trait HasSchemaOperations
 *
 * Provides convenient schema modification operations.
 * All operations check for existence before acting (safe operations).
 *
 * DRY Principle: Reusable schema modification methods
 * KISS Principle: Simple, focused operations
 */
trait HasSchemaOperations
{
    /**
     * Drop columns from table (checks existence first)
     */
    protected function dropColumnsFromTable(string $table, array|string $columns): void
    {
        $columns = (array) $columns;

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $table): void {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $blueprint->dropColumn($column);
                }
            }
        });
    }

    /**
     * Add column if it doesn't exist
     */
    protected function addColumnIfNotExists(
        string $table,
        string $column,
        Closure $definition
    ): void {
        if (! Schema::hasColumn($table, $column)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $definition): void {
                $definition($blueprint, $column);
            });
        }
    }

    /**
     * Rename column if it exists
     */
    protected function renameColumnIfExists(
        string $table,
        string $from,
        string $to
    ): void {
        if (Schema::hasColumn($table, $from)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->renameColumn($from, $to)
            );
        }
    }

    /**
     * Drop tables if they exist
     */
    protected function dropTablesIfExist(string|array $tables): void
    {
        foreach ((array) $tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Drop index if it exists
     *
     * The index may be given as its name (string) or the list of columns it
     * covers (array); Schema::hasIndex() and Blueprint::dropIndex() both accept
     * either form. Existence is checked first so dropping a missing index is a
     * no-op rather than a driver error.
     */
    protected function dropIndexIfExists(string $table, string|array $index): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropIndex($index);
        });
    }
}
