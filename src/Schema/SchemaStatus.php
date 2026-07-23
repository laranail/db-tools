<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

/**
 * The coarse readiness of a database connection, from "cannot reach it" to
 * "has every table the app asked for". Ordered from least to most ready.
 */
enum SchemaStatus: string
{
    /** The connection is unreachable (no server, wrong credentials, blackhole). */
    case Down = 'down';

    /** Reachable, but there is no `migrations` table — nothing has been migrated. */
    case Empty = 'empty';

    /** Migrated, but one or more required tables are still missing. */
    case Pending = 'pending';

    /** Reachable and every required table is present. */
    case Ready = 'ready';

    public function isReady(): bool
    {
        return $this === self::Ready;
    }

    /**
     * A human-facing, action-oriented message per status.
     */
    public function message(): string
    {
        return match ($this) {
            self::Down => 'Database is unreachable. Check the connection settings and that the server is running.',
            self::Empty => 'Database is reachable but nothing has been migrated. Run `php artisan migrate`.',
            self::Pending => 'Database is migrated but some required tables are missing. Run `php artisan migrate` (and `db:seed` if needed).',
            self::Ready => 'Database is ready.',
        };
    }
}
