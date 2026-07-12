<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema\Concerns;

use Illuminate\Support\Facades\Schema;

/**
 * Trait HasSchemaInspection
 *
 * Provides schema inspection capabilities for Eloquent models.
 * Includes caching to minimize database queries.
 *
 * DRY Principle: Single source of truth for schema column information
 */
trait HasSchemaInspection
{
    private static ?array $cachedColumnNames = null;

    private static ?string $cachedTableName = null;

    /**
     * Get the table name for the model
     */
    public static function getSchemaTableName(): string
    {
        return self::$cachedTableName ??= (new static)->getTable();
    }

    /**
     * Get all column names for the model's table
     */
    public static function getSchemaColumnNames(): array
    {
        return self::$cachedColumnNames ??= Schema::getColumnListing(
            self::getSchemaTableName()
        );
    }

    /**
     * Check if the model's table has a specific column
     */
    public static function schemaHasColumn(string $name): bool
    {
        return in_array($name, self::getSchemaColumnNames(), true);
    }

    /**
     * Clear the cached table name and column listing.
     *
     * The column/table cache is static and lives for the whole request (or the
     * whole test process), so it can go stale whenever the underlying schema
     * changes. Call this after running migrations, after altering the table
     * within the same process, or in test tearDown when tables are created and
     * dropped between cases — otherwise schemaHasColumn()/getSchemaColumnNames()
     * will keep reporting the pre-change columns.
     */
    public static function clearSchemaCache(): void
    {
        self::$cachedColumnNames = null;
        self::$cachedTableName = null;
    }
}
