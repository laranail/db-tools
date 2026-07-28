<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Support;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Process-wide memo of "does this table have this column".
 *
 * Schema introspection is not free, and the traits that need this answer ask
 * it on every insert. Left uncached, a bulk import issues one introspection
 * query per row.
 *
 * This deliberately lives in its own class rather than as a static inside the
 * traits that use it. A `static` declared in a trait is copied into every
 * class that uses it, so each model would get a private cache and a
 * `Trait::flush()` call would clear a copy nothing was reading — which is
 * exactly the trap that makes trait-static caches report the wrong answer.
 * One class, one cache, one flush.
 */
final class SchemaColumnCache
{
    /**
     * @var array<string, bool>
     */
    private static array $entries = [];

    /**
     * Whether $model's table has $column, resolved once per
     * connection + table + column.
     *
     * A connection that cannot answer is reported as "no column" rather than
     * being cached, so a transient failure does not pin a wrong answer for the
     * rest of the process.
     */
    public static function has(Model $model, string $column): bool
    {
        $context = ConnectionContext::forModel($model);
        $key = $context->key().'|'.$model->getTable().'|'.$column;

        if (array_key_exists($key, self::$entries)) {
            return self::$entries[$key];
        }

        try {
            return self::$entries[$key] = $context->schema()->hasColumn($model->getTable(), $column);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Forget every memoised answer.
     *
     * Needed wherever the schema changes within a process — most obviously a
     * test suite that drops and recreates its tables between cases.
     */
    public static function flush(): void
    {
        self::$entries = [];
    }
}
