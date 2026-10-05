<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Events;

use Illuminate\Contracts\Events\Dispatcher;

/**
 * The string events `MaintenanceService` dispatches, and the bare names they replace.
 *
 * Event names share one flat registry with the host and every other package, so the
 * package's own names carry its vendor and slug. The bare names are still dispatched,
 * right after the scoped ones, until the next minor after 0.1.
 *
 * `logs:clearing` / `logs:cleared` warn once per process, and only when something listens
 * for them. `cache:clearing` / `cache:cleared` never warn: they are also the names
 * Laravel's own `cache:clear` command dispatches, so a listener on them is most likely
 * the host's listener for that command, and a warning would be wrong about it.
 */
final class MaintenanceEventNames
{
    public const string CACHE_CLEARING = 'laranail-db-tools.cache.clearing';

    public const string CACHE_CLEARED = 'laranail-db-tools.cache.cleared';

    public const string LOGS_CLEARING = 'laranail-db-tools.logs.clearing';

    public const string LOGS_CLEARED = 'laranail-db-tools.logs.cleared';

    /**
     * Scoped name => deprecated bare name.
     *
     * @deprecated The bare names are dispatched until the next minor after 0.1; listen for the
     *             scoped names instead.
     */
    public const array DEPRECATED = [
        self::CACHE_CLEARING => 'cache:clearing',
        self::CACHE_CLEARED  => 'cache:cleared',
        self::LOGS_CLEARING  => 'logs:clearing',
        self::LOGS_CLEARED   => 'logs:cleared',
    ];

    /** Bare names shared with Laravel itself, which never warn. */
    private const array SHARED_WITH_FRAMEWORK = ['cache:clearing', 'cache:cleared'];

    /** @var array<string, true> */
    private static array $warned = [];

    /**
     * Dispatch the scoped event, then its deprecated bare name.
     */
    public static function dispatch(Dispatcher $events, string $scoped): void
    {
        $events->dispatch($scoped);

        $bare = self::DEPRECATED[$scoped] ?? null;

        if ($bare === null) {
            return;
        }

        self::warnIfListened($events, $bare, $scoped);

        $events->dispatch($bare);
    }

    /** Forget which names have been announced. For tests. */
    public static function forgetWarnings(): void
    {
        self::$warned = [];
    }

    private static function warnIfListened(Dispatcher $events, string $bare, string $scoped): void
    {
        if (isset(self::$warned[$bare])
            || in_array($bare, self::SHARED_WITH_FRAMEWORK, true)
            || ! $events->hasListeners($bare)) {
            return;
        }

        self::$warned[$bare] = true;

        trigger_error(sprintf(
            'laranail/db-tools: the [%s] event is deprecated and will no longer be dispatched from the next minor after 0.1. Listen for [%s] instead.',
            $bare,
            $scoped,
        ), E_USER_DEPRECATED);
    }
}
