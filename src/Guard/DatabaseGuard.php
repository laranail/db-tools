<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Guard;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Traits\Macroable;
use Simtabi\Laranail\DbTools\Guard\Contracts\DatabaseAvailabilityInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseConnectionTesterInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseSchemaInspectorInterface;
use Simtabi\Laranail\DbTools\Schema\DatabaseConnectionTester;
use Simtabi\Laranail\DbTools\Schema\DatabaseSchemaInspector;
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
     * Optional custom availability strategy: fn(?string $connection): bool.
     */
    private ?Closure $prober = null;

    public function __construct(
        private readonly DatabaseConnectionTesterInterface $tester,
        private readonly DatabaseSchemaInspectorInterface $inspector,
        private readonly bool $memoize = true,
    ) {}

    public function isAvailable(?string $connection = null): bool
    {
        $key = $connection ?? '__default__';

        if ($this->memoize && array_key_exists($key, $this->available)) {
            return $this->available[$key];
        }

        try {
            $result = $this->prober !== null
                ? (bool) ($this->prober)($connection)
                : $this->tester->test($connection);
        } catch (Throwable) {
            $result = false;
        }

        if ($this->memoize) {
            $this->available[$key] = $result;
        }

        return $result;
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

    public function flush(?string $connection = null): void
    {
        if ($connection === null) {
            $this->available = [];

            return;
        }

        unset($this->available[$connection]);
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

        return $this;
    }

    /**
     * Provider-independent shortcut: true when the connection is reachable.
     * Safe at the earliest boot — self-bootstraps from the container.
     */
    public static function reachable(?string $connection = null): bool
    {
        return static::resolve()->isAvailable($connection);
    }

    /**
     * Provider-independent shortcut: true when the connection is reachable AND
     * the table exists. Use in place of a bare Schema::hasTable() guard that must
     * not throw when the database is down.
     */
    public static function tableExists(string $table, ?string $connection = null): bool
    {
        return static::resolve()->hasTable($table, $connection);
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
        );

        $container->instance(DatabaseAvailabilityInterface::class, $guard);

        return $guard;
    }
}
