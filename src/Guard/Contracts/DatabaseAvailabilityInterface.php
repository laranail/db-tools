<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Guard\Contracts;

/**
 * Interface DatabaseAvailabilityInterface
 *
 * A guard that answers "is the database usable right now?" without throwing.
 * Every method degrades gracefully: when the connection is unreachable (no DB
 * server, wrong credentials, fresh clone, CI/container build) the guard reports
 * "not available" instead of surfacing a QueryException/PDOException.
 */
interface DatabaseAvailabilityInterface
{
    /**
     * True when a PDO connection to the given connection (or the default) can be opened.
     */
    public function isAvailable(?string $connection = null): bool;

    /**
     * True when the connection is available AND the table exists.
     *
     * Returns false both when the DB is unreachable and when the table is simply
     * missing (not yet migrated) — a safe replacement for a bare Schema::hasTable().
     */
    public function hasTable(string $table, ?string $connection = null): bool;

    /**
     * Run $callback only when the connection is available; otherwise return $default.
     *
     * @template TValue
     *
     * @param  callable():TValue  $callback
     * @param  TValue  $default
     * @return TValue
     */
    public function whenAvailable(callable $callback, mixed $default = null, ?string $connection = null): mixed;

    /**
     * Forget the memoized availability result for a connection (or all of them).
     */
    public function flush(?string $connection = null): void;
}
