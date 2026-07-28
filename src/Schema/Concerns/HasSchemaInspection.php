<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema\Concerns;

use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Support\SchemaColumnCache;

/**
 * Trait HasSchemaInspection
 *
 * Schema inspection for Eloquent models, memoised so repeated questions do not
 * each cost an introspection query.
 *
 * The cache is keyed by model class, connection and table. It used to be two
 * plain statics written through self::, which was wrong twice over: a static
 * declared in a trait is shared down the inheritance chain, so whichever class
 * was asked FIRST populated the cache for every subclass — a `Comment extends
 * Post` reported `posts`' columns — and the connection was never part of the
 * key, so the same model read on a second connection got the first one's
 * answer. Both are silent wrong answers rather than errors.
 *
 * The store lives in {@see SchemaColumnCache} rather than here, because a
 * static declared in a trait is copied into every using class: a "clear
 * everything" defined here could only ever clear one copy.
 *
 * @mixin Model
 */
trait HasSchemaInspection
{
    /**
     * Get the table name for the model.
     */
    public static function getSchemaTableName(): string
    {
        return (new static)->getTable();
    }

    /**
     * Get all column names for the model's table.
     *
     * @return list<string>
     */
    public static function getSchemaColumnNames(): array
    {
        return SchemaColumnCache::columns(new static);
    }

    /**
     * Check if the model's table has a specific column.
     */
    public static function schemaHasColumn(string $name): bool
    {
        return in_array($name, static::getSchemaColumnNames(), true);
    }

    /**
     * The column names for THIS instance's table and connection.
     *
     * The static accessors answer for a fresh instance, so they cannot see a
     * connection set per instance — tenancy, a read replica, an explicit
     * setConnection(). Use this when the instance's own connection matters.
     *
     * @return list<string>
     */
    public function schemaColumns(): array
    {
        return SchemaColumnCache::columns($this);
    }

    /**
     * Whether THIS instance's table and connection has the given column.
     */
    public function hasSchemaColumn(string $name): bool
    {
        return in_array($name, $this->schemaColumns(), true);
    }

    /**
     * Clear the cached column listing for this model class.
     *
     * The cache lives for the whole request (or the whole test process), so it
     * goes stale whenever the underlying schema changes. Call this after
     * running migrations, after altering the table within the same process, or
     * in test tearDown when tables are created and dropped between cases —
     * otherwise the pre-change columns keep being reported.
     */
    public static function clearSchemaCache(): void
    {
        SchemaColumnCache::forget(static::class);
    }

    /**
     * Clear every memoised schema answer, for every model class.
     *
     * Invalidation no longer cascades to subclasses — that cascade WAS the bug
     * — so a suite that rebuilds its schema between cases wants this rather
     * than a per-class call for every model it touched.
     */
    public static function clearAllSchemaCaches(): void
    {
        SchemaColumnCache::flush();
    }
}
