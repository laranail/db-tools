<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Support;

use Closure;
use Throwable;
use Illuminate\Support\Arr;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;

/**
 * The single place this package answers "which connection are we talking about?".
 *
 * Every method here exists because the same defect kept recurring: the public API
 * is multi-connection aware (most methods take `?string $connection = null`) while
 * the implementation quietly fell back to the default. Two shipped instances of
 * that — a transaction opened on the default connection while its statements ran
 * on another, and an availability memo that forked `null` from the explicit
 * default name — are why the resolution now lives in one place instead of being
 * re-derived at each call site.
 *
 * Resolution is LAZY. Constructing a context must not touch the container, read
 * config, or build a connection: `BackupManager` needs the configuration of a
 * database that may be unreachable, and `DatabaseGuard` needs a memo key for a
 * connection it has not yet decided to probe.
 *
 * `null` and `''` are the same thing here — "the application default". The empty
 * string arrives from a bare `--connection=` on the CLI, and it is neither a
 * usable memo key nor a usable `database.connections.*` segment.
 */
final class ConnectionContext
{
    /**
     * Memo key used when the default connection name cannot be resolved, i.e. at
     * the earliest boot when no container or config repository exists yet.
     *
     * Deliberately `__default__`: two of the three sentinels this class replaces
     * already used it, so degraded-path memo keys do not move.
     */
    public const string UNRESOLVED = '__default__';

    /** Per-instance memo. Never static — see {@see defaultConnectionName()}. */
    private ?string $key = null;

    /**
     * @param string|null $requested the caller's name, already normalised
     * @param Connection|null $connection pre-bound, or memoised by connection()
     * @param (Closure(): Connection)|null $resolver a model's own resolver
     */
    private function __construct(
        private readonly ?string $requested,
        private ?Connection $connection = null,
        private readonly ?Closure $resolver = null,
    ) {}

    /**
     * A context for an explicitly named connection, or the default when null/''.
     */
    public static function for(?string $connection): self
    {
        return new self($connection === '' ? null : $connection);
    }

    /** A context wrapping an already-resolved connection. */
    public static function forConnection(Connection $connection): self
    {
        return new self(null, $connection);
    }

    /**
     * The connection an Eloquent model is bound to.
     *
     * Goes through the model's own resolver rather than the `DB` facade, so a
     * per-instance `setConnection()` or a custom connection resolver is honoured.
     */
    public static function forModel(Model $model): self
    {
        return new self(
            $model->getConnectionName(),
            null,
            static fn (): Connection => $model->getConnection(),
        );
    }

    /**
     * The connection a schema Blueprint is building against.
     *
     * `Blueprint::$connection` is protected and Laravel exposes no getter, so the
     * read is scoped here — once — rather than in every macro. Falls back to the
     * default connection instead of throwing inside a migration.
     */
    public static function forBlueprint(Blueprint $blueprint): self
    {
        /** @var Closure(): ?Connection $read */
        $read = Closure::bind(
            function (): ?Connection {
                /** @var Blueprint $this */
                return $this->connection ?? null;
            },
            $blueprint,
            Blueprint::class,
        );

        $connection = $read();

        return $connection instanceof Connection
            ? self::forConnection($connection)
            : self::for(null);
    }

    /**
     * The name the caller asked for, or null when they asked for the default.
     *
     * Only for report fields that must preserve "was this explicit?". Use
     * {@see key()} for anything that identifies a connection.
     */
    public function requestedName(): ?string
    {
        return $this->requested;
    }

    /**
     * A stable, non-empty key for this connection. Never throws.
     *
     * `null`, `''` and the explicit default connection name all produce the same
     * string, so a memo cannot fork the way the availability guard's did.
     */
    public function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        // An explicit name needs neither container nor config.
        if ($this->requested !== null) {
            return $this->key = $this->requested;
        }

        // A pre-bound connection already knows its own name.
        if ($this->connection instanceof Connection) {
            $name = $this->connection->getName();

            if (is_string($name) && $name !== '') {
                return $this->key = $name;
            }
        }

        return $this->key = $this->defaultConnectionName();
    }

    /** The resolved connection. */
    public function connection(): Connection
    {
        return $this->connection ??= $this->resolver instanceof Closure
            ? ($this->resolver)()
            : Container::getInstance()->make('db')->connection($this->requested);
    }

    /** The schema builder for this connection. */
    public function schema(): Builder
    {
        return $this->connection()->getSchemaBuilder();
    }

    /**
     * This connection's configuration.
     *
     * Read from the config repository, never from `connection()->getConfig()`, so
     * backing up or restoring an unreachable database still works.
     *
     * @return array<string, mixed>|null
     */
    public function configArray(): ?array
    {
        $config = $this->configValue($this->configPath());

        return is_array($config) ? $config : null;
    }

    /** A single value from {@see configArray()}, via dot notation. */
    public function config(string $option, mixed $default = null): mixed
    {
        return Arr::get($this->configArray() ?? [], $option, $default);
    }

    /**
     * The config path this connection's settings live at.
     *
     * For the rare caller that must *write* config rather than read it — the
     * availability probe overlays a short connect timeout and restores it — so
     * that even a write does not have to spell the key itself.
     */
    public function configPath(): string
    {
        return 'database.connections.' . $this->key();
    }

    /**
     * The configured default connection name.
     *
     * Deliberately NOT memoised process-wide: the test suite rewrites
     * `database.default` per case, the connection probe rewrites
     * `database.connections.*` at runtime, and tenancy packages swap the default
     * mid-request. A stale cached default would be this same defect again.
     */
    private function defaultConnectionName(): string
    {
        $default = $this->configValue('database.default');

        return is_string($default) && $default !== '' ? $default : self::UNRESOLVED;
    }

    /**
     * Read config without assuming the container is booted.
     *
     * The guard matters at the earliest boot — the availability guard's static
     * entry points are documented as usable before this package's provider has
     * registered.
     */
    private function configValue(string $key): mixed
    {
        try {
            $container = Container::getInstance();

            if (! $container->bound('config')) {
                return null;
            }

            return $container->make('config')->get($key);
        } catch (Throwable) {
            return null;
        }
    }
}
