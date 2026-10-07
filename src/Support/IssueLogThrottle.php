<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Support;

use Throwable;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Decides whether a database issue is worth a log line: the first observation of
 * a state, then at most one reminder per `guard.log_reminder_interval` seconds
 * while it persists, and the moment it clears.
 *
 * Without it, a health check probing a dead database every 15 seconds wrote two
 * warnings per probe, about 11,500 lines a day, burying the outage's own log.
 *
 * State is shared through the cache (`Cache::add` with a TTL is the atomic "first
 * one in this window logs"), keyed by connection, kind and state. The cache may
 * itself be the database that is down, so every operation that throws is retried
 * against an in-process static store. In a short-lived process (PHP-FPM) that
 * fallback lasts one request, which is why `guard.log_cache_store` defaults to
 * `file` rather than the application's default store.
 */
final class IssueLogThrottle
{
    use InteractsWithTime;

    /** Default reminder interval, in seconds. */
    public const int DEFAULT_INTERVAL = 300;

    /** How long a "this connection is in state X" marker outlives its last write. */
    private const int MARKER_TTL = 86_400;

    private const string PREFIX = 'laranail.db-tools.log.';

    /**
     * The in-process store used when the cache throws.
     *
     * @var array<string, array{value: mixed, expires: int}>
     */
    private static array $fallback = [];

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly ConfigRepository $config,
    ) {}

    /** Reset the in-process fallback. For tests and long-lived workers. */
    public static function forgetFallback(): void
    {
        self::$fallback = [];
    }

    /**
     * Record that `$connection` is in `$state` for `$kind` (e.g. "availability",
     * "schema"). Returns null when the line should be suppressed, otherwise when
     * the state began and whether this line is a reminder of an earlier one.
     *
     * @return array{since: int, reminder: bool}|null
     */
    public function enter(?string $connection, string $kind, string $state): ?array
    {
        try {
            return $this->decide($this->store(), $connection, $kind, $state);
        } catch (Throwable) {
            return $this->decide(null, $connection, $kind, $state);
        }
    }

    /**
     * Record that `$connection` has left whatever state it was in for `$kind`.
     * Returns when that state began, or null when it was not in one (so there
     * is no recovery to report).
     */
    public function leave(?string $connection, string $kind): ?int
    {
        $marker = $this->markerKey($connection, $kind);

        try {
            $cached = $this->clear($this->store(), $connection, $kind, $marker);
        } catch (Throwable) {
            $cached = null;
        }

        // Checked even when the cache answered: an outage recorded while the
        // cache was down lives only here.
        $local = $this->clear(null, $connection, $kind, $marker);

        return $cached ?? $local;
    }

    /**
     * @return array{since: int, reminder: bool}|null
     */
    private function decide(?Repository $store, ?string $connection, string $kind, string $state): ?array
    {
        $marker = $this->markerKey($connection, $kind);
        $interval = $this->interval();
        $now = $this->currentTime();
        $current = $this->get($store, $marker);
        $previous = is_array($current) && is_string($current['state'] ?? null) ? $current['state'] : null;
        $sameState = $previous === $state;

        // A change of state is news however recently the old one was logged, and
        // must not leave the old state's window behind to swallow a flip back.
        if ($previous !== null && ! $sameState) {
            $this->forget($store, $this->throttleKey($connection, $kind, $previous));
        }

        // Zero switches throttling off: every check logs, as it did before.
        if ($interval > 0 && ! $this->add($store, $this->throttleKey($connection, $kind, $state), $now, $interval)) {
            return null;
        }

        if ($sameState && is_int($current['since'] ?? null)) {
            $this->put($store, $marker, $current, self::MARKER_TTL);

            return ['since' => $current['since'], 'reminder' => $interval > 0];
        }

        $this->put($store, $marker, ['state' => $state, 'since' => $now], self::MARKER_TTL);

        return ['since' => $now, 'reminder' => false];
    }

    private function clear(?Repository $store, ?string $connection, string $kind, string $marker): ?int
    {
        $current = $this->get($store, $marker);

        if (! is_array($current)) {
            return null;
        }

        $this->forget($store, $marker);

        if (is_string($current['state'] ?? null)) {
            $this->forget($store, $this->throttleKey($connection, $kind, $current['state']));
        }

        return is_int($current['since'] ?? null) ? $current['since'] : null;
    }

    private function get(?Repository $store, string $key): mixed
    {
        if ($store instanceof Repository) {
            return $store->get($key);
        }

        $entry = self::$fallback[$key] ?? null;

        if ($entry === null || $entry['expires'] <= $this->currentTime()) {
            unset(self::$fallback[$key]);

            return null;
        }

        return $entry['value'];
    }

    private function put(?Repository $store, string $key, mixed $value, int $ttl): void
    {
        if ($store instanceof Repository) {
            $store->put($key, $value, $ttl);

            return;
        }

        self::$fallback[$key] = ['value' => $value, 'expires' => $this->currentTime() + $ttl];
    }

    private function add(?Repository $store, string $key, mixed $value, int $ttl): bool
    {
        if ($store instanceof Repository) {
            return $store->add($key, $value, $ttl);
        }

        if ($this->get(null, $key) !== null) {
            return false;
        }

        $this->put(null, $key, $value, $ttl);

        return true;
    }

    private function forget(?Repository $store, string $key): void
    {
        if ($store instanceof Repository) {
            $store->forget($key);

            return;
        }

        unset(self::$fallback[$key]);
    }

    private function store(): Repository
    {
        $name = $this->config->get('laranail.db-tools.guard.log_cache_store', 'file');

        return $this->cache->store(is_string($name) && $name !== '' ? $name : null);
    }

    private function interval(): int
    {
        $interval = $this->config->get('laranail.db-tools.guard.log_reminder_interval', self::DEFAULT_INTERVAL);

        return is_numeric($interval) ? max(0, (int) $interval) : self::DEFAULT_INTERVAL;
    }

    private function markerKey(?string $connection, string $kind): string
    {
        return self::PREFIX . ConnectionContext::for($connection)->key() . '.' . $kind;
    }

    private function throttleKey(?string $connection, string $kind, string $state): string
    {
        return $this->markerKey($connection, $kind) . '.' . $state . '.throttle';
    }
}
