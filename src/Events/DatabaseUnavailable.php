<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by {@see DatabaseGuard} the first time a
 * connection is probed and found unreachable within the request/command (the
 * result is memoized, so this fires once per connection, not per check). Not
 * fired while the guard is suspended — a suspension is a deliberate "don't
 * probe" state, not an outage.
 *
 * Listen to alert on a database that has gone away in production.
 */
final readonly class DatabaseUnavailable
{
    use Dispatchable;

    public function __construct(
        public ?string $connection = null,
    ) {}
}
