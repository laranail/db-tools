<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by {@see DatabaseGuard} when a connection is probed and found
 * reachable, the first time in a process and on every transition back from
 * unavailable. The counterpart of {@see DatabaseUnavailable}: the default log
 * listener uses it to record recovery once, rather than inferring it from the
 * warnings stopping.
 */
final readonly class DatabaseAvailable
{
    use Dispatchable;

    public function __construct(
        public ?string $connection = null,
    ) {}
}
