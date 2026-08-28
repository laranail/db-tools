<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema\Contracts;

use Simtabi\Laranail\DbTools\Schema\SchemaReadinessReport;

/**
 * Reports whether a database is reachable, migrated, and has the tables an app
 * needs — without ever throwing. Lets an application proceed and warn instead
 * of failing blind when the schema is not yet in place.
 */
interface SchemaReadinessInterface
{
    /**
     * Build a readiness report for the given required tables (falling back to
     * the configured default set when none are given).
     *
     * @param list<string> $requiredTables
     */
    public function report(array $requiredTables = [], ?string $connection = null): SchemaReadinessReport;

    /**
     * True when the connection is reachable and every required table exists.
     *
     * @param list<string> $requiredTables
     */
    public function isReady(array $requiredTables = [], ?string $connection = null): bool;

    /**
     * Run $callback only when the schema is ready; otherwise return $default.
     *
     * @template TValue
     *
     * @param callable():TValue $callback
     * @param TValue $default
     * @param list<string> $requiredTables
     *
     * @return TValue
     */
    public function whenReady(callable $callback, mixed $default = null, array $requiredTables = [], ?string $connection = null): mixed;

    /**
     * Forget memoized reports for a connection (or all of them) and flush the
     * underlying availability memo, so the next call re-evaluates.
     *
     * Reports are memoized for the lifetime of the instance, which assumes a
     * short-lived process. Call this in a long-lived one (Octane, a queue
     * worker, or after running migrations in-process) when the schema may have
     * changed since the last report.
     */
    public function flush(?string $connection = null): void;
}
