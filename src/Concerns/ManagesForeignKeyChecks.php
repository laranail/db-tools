<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Closure;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Temporarily disable and re-enable foreign key constraints around a callback.
 *
 * Nested calls are tracked so constraints are only disabled on the outermost
 * call and re-enabled once every nested call has completed.
 *
 * The nesting depth is keyed per database connection in a static map, so
 * concurrent toggles across different connections (or across separate
 * instances of the consuming class) cannot corrupt each other's counter: a
 * nested toggle on connection "tenant" never re-enables constraints on the
 * default connection, and vice versa.
 */
trait ManagesForeignKeyChecks
{
    /**
     * Per-connection nesting depth, keyed by connection name.
     *
     * @var array<string, int>
     */
    private static array $foreignKeyNestingLevels = [];

    /**
     * Run a callback with foreign key constraints disabled.
     *
     * @param  string|null  $connection  Connection name (null for default)
     *
     * @example
     * $this->withoutForeignKeyChecks(function (): void {
     *     User::truncate();
     *     Post::truncate();
     * });
     */
    protected function withoutForeignKeyChecks(Closure $callback, ?string $connection = null): mixed
    {
        $this->disableForeignKeyChecks($connection);

        try {
            return $callback();
        } finally {
            $this->enableForeignKeyChecks($connection);
        }
    }

    /**
     * Disable foreign key constraints (only on the outermost call).
     */
    private function disableForeignKeyChecks(?string $connection = null): void
    {
        $key = $this->foreignKeyConnectionKey($connection);

        if ((self::$foreignKeyNestingLevels[$key] ?? 0) === 0) {
            $this->schemaFor($connection)->disableForeignKeyConstraints();
        }

        self::$foreignKeyNestingLevels[$key] = (self::$foreignKeyNestingLevels[$key] ?? 0) + 1;
    }

    /**
     * Re-enable foreign key constraints (only once all nested calls finish).
     */
    private function enableForeignKeyChecks(?string $connection = null): void
    {
        $key = $this->foreignKeyConnectionKey($connection);

        $level = max(0, (self::$foreignKeyNestingLevels[$key] ?? 0) - 1);

        if ($level === 0) {
            $this->schemaFor($connection)->enableForeignKeyConstraints();
            unset(self::$foreignKeyNestingLevels[$key]);

            return;
        }

        self::$foreignKeyNestingLevels[$key] = $level;
    }

    /**
     * Current nesting level for a connection (useful for debugging/testing).
     */
    protected function getForeignKeyCheckNestingLevel(?string $connection = null): int
    {
        return self::$foreignKeyNestingLevels[$this->foreignKeyConnectionKey($connection)] ?? 0;
    }

    /**
     * Resolve the static-map key for a connection (default when null).
     */
    private function foreignKeyConnectionKey(?string $connection): string
    {
        return $connection ?? (string) config('database.default');
    }

    /**
     * Resolve the schema builder for the given connection.
     */
    private function schemaFor(?string $connection): Builder
    {
        return $connection
            ? Schema::connection($connection)
            : Schema::connection(DB::getDefaultConnection());
    }
}
