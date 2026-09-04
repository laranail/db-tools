<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

use Closure;
use Override;
use Throwable;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\ColumnDefinition;
use Simtabi\Laranail\DbTools\Providers\DbToolsServiceProvider;
use Illuminate\Database\Schema\Blueprint as IlluminateBlueprint;

/**
 * An `Illuminate\Database\Schema\Blueprint` whose `id()`, `foreignId()`,
 * `morphs()` and `nullableMorphs()` follow the configured key type, plus a
 * per-driver setup hook.
 *
 * ## How it is installed
 *
 * Turn on `laranail.db-tools.schema.blueprint_macros` and
 * {@see DbToolsServiceProvider} binds this
 * class over `Illuminate\Database\Schema\Blueprint` in the container. Laravel's
 * schema builder resolves every blueprint through
 * `Container::make(Blueprint::class, …)`, so the binding reaches every
 * connection and every migration with nothing else to wire.
 *
 * `Schema::blueprintResolver()` is deliberately not used: `Connection::
 * getSchemaBuilder()` returns a **new** builder on each call, so a resolver set
 * on one instance is lost on the next, and the behaviour would depend on
 * whether the caller happened to go through the cached `Schema` facade.
 *
 * Before 0.7.1 nothing installed this class at all — it was documented as
 * "subclass and pass to a custom Schema builder", which no application did, so
 * the overrides never ran and `registerDriverSetup()` was unreachable.
 */
class BlueprintMacros extends IlluminateBlueprint
{
    private static ?Closure $idTypeResolver = null;

    /** @var array<string, Closure> */
    private static array $driverSetup = [];

    /**
     * Connections whose driver setup has already run, keyed driver@connection.
     *
     * @var array<string, true>
     */
    private static array $driverSetupRan = [];

    public function __construct(Connection $connection, $table, ?Closure $callback = null)
    {
        parent::__construct($connection, $table, $callback);

        $this->runDriverSetup($connection);
    }

    /**
     * Set custom ID type resolver
     */
    public static function setIdTypeResolver(Closure $resolver): void
    {
        self::$idTypeResolver = $resolver;
    }

    /**
     * Register driver-specific setup callback.
     *
     * Runs once per connection, on the first blueprint built for it — not once
     * per blueprint. A migration touching forty tables should not issue the
     * same `SET SESSION` forty times.
     *
     * The trade-off: setup is session state, so a reconnect loses it and this
     * will not re-apply until {@see flushDriverSetupState()} is called. Use it
     * for session tuning, not for anything correctness depends on.
     *
     * @example
     * BlueprintMacros::registerDriverSetup('mysql', function ($connection): void {
     *     $connection->statement('SET SESSION sql_require_primary_key=0');
     * });
     */
    public static function registerDriverSetup(string $driver, Closure $setup): void
    {
        self::$driverSetup[$driver] = $setup;
        self::$driverSetupRan = [];
    }

    /**
     * Forget which connections have been set up, so the next blueprint re-runs
     * the callback. Mainly for tests and for use after a reconnect.
     */
    public static function flushDriverSetupState(): void
    {
        self::$driverSetupRan = [];
    }

    #[Override]
    public function id($column = 'id'): ColumnDefinition
    {
        $idType = self::$idTypeResolver instanceof Closure
            ? (self::$idTypeResolver)()
            : 'BIGINT';

        return match ($idType) {
            'UUID'  => $this->uuid($column)->primary(),
            'ULID'  => $this->ulid($column)->primary(),
            default => parent::id($column),
        };
    }

    #[Override]
    public function foreignId($column): ColumnDefinition
    {
        $idType = self::$idTypeResolver instanceof Closure
            ? (self::$idTypeResolver)()
            : 'BIGINT';

        return match ($idType) {
            'UUID'  => $this->foreignUuid($column),
            'ULID'  => $this->foreignUlid($column),
            default => parent::foreignId($column),
        };
    }

    #[Override]
    public function morphs($name, $indexName = null, $after = null): void
    {
        $idType = self::$idTypeResolver instanceof Closure
            ? (self::$idTypeResolver)()
            : 'BIGINT';

        // uuidMorphs()/ulidMorphs() accept $after just as morphs() does, but
        // it used to be dropped on these two paths — so a migration placing
        // morph columns at a chosen position silently got them appended, on
        // exactly the id types this class exists to support.
        match ($idType) {
            'UUID'  => $this->uuidMorphs($name, $indexName, $after),
            'ULID'  => $this->ulidMorphs($name, $indexName, $after),
            default => parent::morphs($name, $indexName, $after),
        };
    }

    #[Override]
    public function nullableMorphs($name, $indexName = null, $after = null): void
    {
        $idType = self::$idTypeResolver instanceof Closure
            ? (self::$idTypeResolver)()
            : 'BIGINT';

        match ($idType) {
            'UUID'  => $this->nullableUuidMorphs($name, $indexName, $after),
            'ULID'  => $this->nullableUlidMorphs($name, $indexName, $after),
            default => parent::nullableMorphs($name, $indexName, $after),
        };
    }

    /**
     * Apply the registered setup for this connection's driver, once.
     */
    private function runDriverSetup(Connection $connection): void
    {
        $driver = $connection->getDriverName();

        if (! isset(self::$driverSetup[$driver])) {
            return;
        }

        $key = $driver . '@' . $connection->getName();

        if (isset(self::$driverSetupRan[$key])) {
            return;
        }

        self::$driverSetupRan[$key] = true;

        try {
            (self::$driverSetup[$driver])($connection);
        } catch (Throwable $e) {
            // Best-effort setup, but never swallow silently: surface the
            // failure so a broken driver-setup callback is debuggable.
            error_log(sprintf(
                '[laranail/db-tools] driver setup for "%s" failed: %s',
                $driver,
                $e->getMessage(),
            ));
        }
    }
}
