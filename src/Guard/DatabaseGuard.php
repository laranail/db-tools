<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Guard;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Traits\Macroable;
use Simtabi\Laranail\DbTools\Events\DatabaseUnavailable;
use Simtabi\Laranail\DbTools\Guard\Contracts\DatabaseAvailabilityInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseConnectionTesterInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseSchemaInspectorInterface;
use Simtabi\Laranail\DbTools\Schema\DatabaseConnectionTester;
use Simtabi\Laranail\DbTools\Schema\DatabaseSchemaInspector;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;
use Simtabi\Laranail\DbTools\Support\SafeEvent;
use Throwable;

/**
 * Boot-safe database-availability guard.
 *
 * Layered on the existing {@see DatabaseConnectionTesterInterface} (PDO probe)
 * and {@see DatabaseSchemaInspectorInterface} (table lookup): availability is
 * probed once per connection and memoized so repeated boot-time checks stay
 * cheap; `tableExists()`/`hasTable()` never throw when the connection is down.
 *
 * The strategy is runtime-swappable (`probeUsing()`) and the class is
 * `Macroable`. The static entry points self-bootstrap from the container so
 * they are safe at the earliest boot — even before this package's provider is
 * registered.
 */
final class DatabaseGuard implements DatabaseAvailabilityInterface
{
    use Macroable;

    /**
     * Memoized availability results keyed by resolved connection name.
     *
     * @var array<string, bool>
     */
    private array $available = [];

    /**
     * Last availability announced per connection, so DatabaseUnavailable fires
     * on the transition into unavailability rather than on every check.
     *
     * @var array<string, bool>
     */
    private array $announced = [];

    /**
     * Optional custom availability strategy: fn(?string $connection): bool.
     */
    private ?Closure $prober = null;

    /**
     * When true, every availability check short-circuits to false without
     * probing. See {@see suspend()}.
     */
    private bool $suspended = false;

    public function __construct(
        private readonly DatabaseConnectionTesterInterface $tester,
        private readonly DatabaseSchemaInspectorInterface $inspector,
        private readonly bool $memoize = true,
        private readonly bool $emitEvents = true,
    ) {}

    public function isAvailable(?string $connection = null): bool
    {
        if ($this->suspended) {
            return false;
        }

        $key = $this->resolveKey($connection);

        if ($this->memoize && array_key_exists($key, $this->available)) {
            return $this->available[$key];
        }

        try {
            $result = $this->prober instanceof Closure
                ? (bool) ($this->prober)($connection)
                : $this->probeDefault($connection);
        } catch (Throwable) {
            $result = false;
        }

        if ($this->memoize) {
            $this->available[$key] = $result;
        }

        // Announce the transition, not every check. With memoization off a
        // sustained outage would otherwise emit one event per call — an event
        // storm precisely when the system is already unhealthy.
        if (! $result && $this->emitEvents && ($this->announced[$key] ?? true)) {
            SafeEvent::dispatch(new DatabaseUnavailable($connection));
        }

        $this->announced[$key] = $result;

        return $result;
    }

    /**
     * Memo key for a connection. `null` and the explicit default-connection name
     * address the same physical connection, so they must share one entry —
     * otherwise the same connection is probed twice and `flush('mysql')` leaves
     * a stale entry behind for the `null` form.
     */
    private function resolveKey(?string $connection): string
    {
        return ConnectionContext::for($connection)->key();
    }

    /**
     * The built-in probe: a bounded-timeout connection attempt so an
     * unreachable or blackholed host fails fast instead of blocking for the
     * driver's default connect timeout.
     */
    private function probeDefault(?string $connection): bool
    {
        return $this->tester->probe($connection);
    }

    public function hasTable(string $table, ?string $connection = null): bool
    {
        if (! $this->isAvailable($connection)) {
            return false;
        }

        try {
            return $this->inspector->hasTable($table, $connection);
        } catch (Throwable) {
            return false;
        }
    }

    public function whenAvailable(callable $callback, mixed $default = null, ?string $connection = null): mixed
    {
        return $this->isAvailable($connection) ? $callback() : $default;
    }

    /**
     * Run $callback only when $table exists (and the connection is reachable);
     * otherwise return $default. The common "guarded table access" shape.
     *
     * @template TValue
     *
     * @param  callable():TValue  $callback
     * @param  TValue  $default
     * @return TValue
     */
    public function whenTable(string $table, callable $callback, mixed $default = null, ?string $connection = null): mixed
    {
        return $this->hasTable($table, $connection) ? $callback() : $default;
    }

    public function suspend(): static
    {
        $this->suspended = true;

        return $this;
    }

    public function resume(): static
    {
        $this->suspended = false;
        $this->available = [];
        $this->announced = [];

        return $this;
    }

    public function isSuspended(): bool
    {
        return $this->suspended;
    }

    /**
     * Forget the memoized result for a connection (or all of them). A flush is
     * an explicit "re-evaluate", so the announcement state is cleared too and a
     * still-down connection will emit DatabaseUnavailable again.
     */
    public function flush(?string $connection = null): void
    {
        if ($connection === null) {
            $this->available = [];
            $this->announced = [];

            return;
        }

        $key = $this->resolveKey($connection);

        unset($this->available[$key], $this->announced[$key]);
    }

    /**
     * Swap the availability strategy at runtime (e.g. a TCP ping, a cached flag,
     * or a health-check endpoint). Pass null to restore the default PDO probe.
     * Clears the memo since the strategy changed.
     */
    public function probeUsing(?callable $prober): static
    {
        $this->prober = $prober === null ? null : Closure::fromCallable($prober);
        $this->available = [];
        $this->announced = [];

        return $this;
    }

    /**
     * Provider-independent shortcut: true when the connection is reachable.
     * Safe at the earliest boot — self-bootstraps from the container.
     */
    public static function reachable(?string $connection = null): bool
    {
        return self::resolve()->isAvailable($connection);
    }

    /**
     * Provider-independent shortcut: true when the connection is reachable AND
     * the table exists. Use in place of a bare Schema::hasTable() guard that must
     * not throw when the database is down.
     */
    public static function tableExists(string $table, ?string $connection = null): bool
    {
        return self::resolve()->hasTable($table, $connection);
    }

    /**
     * The bound singleton, or a self-bootstrapped instance built from the
     * (no-arg) tester/inspector and stored back into the container.
     */
    public static function resolve(): DatabaseAvailabilityInterface
    {
        $container = Container::getInstance();

        if ($container->bound(DatabaseAvailabilityInterface::class)) {
            $bound = $container->make(DatabaseAvailabilityInterface::class);

            if ($bound instanceof DatabaseAvailabilityInterface) {
                return $bound;
            }
        }

        $guard = new self(
            $container->bound(DatabaseConnectionTesterInterface::class)
                ? $container->make(DatabaseConnectionTesterInterface::class)
                : new DatabaseConnectionTester,
            $container->bound(DatabaseSchemaInspectorInterface::class)
                ? $container->make(DatabaseSchemaInspectorInterface::class)
                : new DatabaseSchemaInspector,
            self::configFlag($container, 'memoize'),
            self::configFlag($container, 'emit_events'),
        );

        $container->instance(DatabaseAvailabilityInterface::class, $guard);

        return $guard;
    }

    /**
     * Read a `guard.*` boolean, defaulting to true when config is unavailable.
     *
     * Without this a self-bootstrapped guard silently ignores the application's
     * `laranail.db-tools.guard.*` settings, so it would behave differently from
     * the one the service provider builds.
     */
    private static function configFlag(Container $container, string $name): bool
    {
        try {
            if (! $container->bound('config')) {
                return true;
            }

            return (bool) $container->make('config')->get("laranail.db-tools.guard.{$name}", true);
        } catch (Throwable) {
            return true;
        }
    }
}
