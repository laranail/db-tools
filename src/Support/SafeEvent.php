<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Support;

use Throwable;
use Illuminate\Container\Container;

/**
 * Fire an event only if an event dispatcher is actually bound, swallowing any
 * error. The guard and readiness reporter run at the earliest boot — before a
 * dispatcher may exist, and in contexts (console bootstrap, package discovery)
 * where a listener throwing must never take the process down. This keeps event
 * emission strictly best-effort.
 */
final class SafeEvent
{
    public static function dispatch(object $event): void
    {
        $container = Container::getInstance();

        if (! $container->bound('events')) {
            return;
        }

        try {
            $container->make('events')->dispatch($event);
        } catch (Throwable) {
            // An event is a notification, never a dependency of the check that
            // raised it. A broken listener must not break availability probing.
        }
    }
}
