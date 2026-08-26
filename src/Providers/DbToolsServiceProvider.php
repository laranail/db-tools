<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Providers;

use Override;
use Throwable;
use Psr\Log\LoggerInterface;
use Illuminate\Routing\Router;
use Illuminate\Foundation\AliasLoader;
use Simtabi\Laranail\Package\Tools\Package;
use Illuminate\Console\Events\CommandStarting;
use Simtabi\Laranail\DbTools\Guard\DatabaseGuard;
use Simtabi\Laranail\DbTools\Backup\BackupManager;
use Simtabi\Laranail\DbTools\Console\HealthCommand;
use Simtabi\Laranail\DbTools\Events\SchemaNotReady;
use Simtabi\Laranail\DbTools\Facades\DbToolsFacade;
use Simtabi\Laranail\DbTools\Console\DbToolsCommand;
use Simtabi\Laranail\DbTools\Schema\BlueprintMacros;
use Simtabi\Laranail\DbTools\Schema\SchemaReadiness;
use Simtabi\Laranail\DbTools\Schema\FieldGroupMacros;
use Simtabi\Laranail\DbTools\Schema\AuditColumnsMacro;
use Simtabi\Laranail\DbTools\Services\DatabaseService;
use Simtabi\Laranail\DbTools\Files\DatabaseFileService;
use Simtabi\Laranail\DbTools\Events\DatabaseUnavailable;
use Simtabi\Laranail\DbTools\Listeners\LogDatabaseIssues;
use Simtabi\Laranail\DbTools\Services\MaintenanceService;
use Simtabi\Laranail\DbTools\Schema\ConfiguredMorphsMacro;
use Simtabi\Laranail\DbTools\Schema\DatabaseTableVerifier;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Simtabi\Laranail\DbTools\Schema\SoftDeleteHistoryMacro;
use Simtabi\Laranail\DbTools\Services\CleanDatabaseService;
use Simtabi\Laranail\DbTools\Schema\DatabaseSchemaInspector;
use Simtabi\Laranail\DbTools\Schema\DatabaseConnectionTester;
use Simtabi\Laranail\DbTools\Schema\SoftDeletesWithUndoMacro;
use Illuminate\Database\Schema\Blueprint as IlluminateBlueprint;
use Simtabi\Laranail\DbTools\Http\Middleware\EnsureSchemaIsReady;
use Simtabi\Laranail\DbTools\Migrations\GuardsDestructiveCommands;
use Simtabi\Laranail\Package\Tools\Providers\PackageServiceProvider;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupManagerInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;
use Simtabi\Laranail\DbTools\Services\Contracts\DatabaseServiceInterface;
use Simtabi\Laranail\DbTools\Files\Contracts\DatabaseFileServiceInterface;
use Simtabi\Laranail\DbTools\Guard\Contracts\DatabaseAvailabilityInterface;
use Simtabi\Laranail\DbTools\Services\Contracts\MaintenanceServiceInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseTableVerifierInterface;
use Simtabi\Laranail\DbTools\Services\Contracts\CleanDatabaseServiceInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseSchemaInspectorInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseConnectionTesterInterface;

final class DbToolsServiceProvider extends PackageServiceProvider
{
    /**
     * Config, commands and publish tags are declared here rather than hand-wired below.
     *
     * `->name('laranail/db-tools')` is what makes the rest resolve: the config file
     * `config/db-tools.php` merges under `laranail.db-tools` and publishes to
     * `config_path('laranail/db-tools.php')` -- the same key and the same destination this
     * provider wired by hand -- and `setPublishTagId('db-tools')` mints `laranail::db-tools-config`
     * in place of the bare `db-tools-config` it used to register.
     */
    #[Override]
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laranail/db-tools')
            ->setPublishTagId('db-tools')
            ->hasConfigFile('db-tools')
            ->hasCommands(DbToolsCommand::class, HealthCommand::class);
    }

    /**
     * Runs inside the base's register(), after the package is configured.
     */
    #[Override]
    public function packageRegistered(): void
    {
        // Backup
        $this->app->singleton(BackupManagerInterface::class, BackupManager::class);

        // Files
        $this->app->singleton(DatabaseFileServiceInterface::class, DatabaseFileService::class);

        // Schema / Inspection
        $this->app->singleton(DatabaseSchemaInspectorInterface::class, DatabaseSchemaInspector::class);
        $this->app->singleton(DatabaseTableVerifierInterface::class, DatabaseTableVerifier::class);
        $this->app->singleton(DatabaseConnectionTesterInterface::class, DatabaseConnectionTester::class);

        // Boot-safe availability guard (never-throws), layered on the tester + inspector.
        //
        // singletonIf, not singleton: DatabaseGuard::resolve() self-bootstraps and
        // binds an instance when a static entry point is used before this provider
        // registers. Rebinding here would discard that guard along with any state
        // already applied to it — most importantly a suspend(), which would
        // silently un-suspend. The early instance reads the same config defaults,
        // so keeping it costs nothing.
        $this->app->singletonIf(DatabaseAvailabilityInterface::class, fn ($app): DatabaseGuard => new DatabaseGuard(
            $app->make(DatabaseConnectionTesterInterface::class),
            $app->make(DatabaseSchemaInspectorInterface::class),
            (bool) $app->make('config')->get('laranail.db-tools.guard.memoize', true),
            (bool) $app->make('config')->get('laranail.db-tools.guard.emit_events', true),
        ));

        // Boot-safe schema-readiness reporter (reachable | migrated | ready).
        $this->app->singleton(SchemaReadinessInterface::class, fn ($app): SchemaReadiness => new SchemaReadiness(
            $app->make(DatabaseAvailabilityInterface::class),
            $app->make(DatabaseTableVerifierInterface::class),
            (bool) $app->make('config')->get('laranail.db-tools.guard.emit_events', true),
        ));

        // General DB service
        $this->app->singleton(DatabaseServiceInterface::class, DatabaseService::class);

        // Truncation with foreign keys handled and protected tables honoured.
        $this->app->singleton(CleanDatabaseServiceInterface::class, CleanDatabaseService::class);

        // Filesystem maintenance (caches, logs, storage symlink)
        $this->app->singleton(MaintenanceServiceInterface::class, fn ($app): MaintenanceService => new MaintenanceService(
            $app->make(LoggerInterface::class),
            $app->basePath(),
        ));

        // Register the facade alias only when the name is still free. The guard
        // was inverted (`class_exists`), which registered the alias only in the
        // case where it was already resolvable — a no-op — and skipped it
        // otherwise. Target the facade, matching composer.json's
        // extra.laravel.aliases; package discovery normally does this already,
        // so this is the fallback for a manually-registered provider.
        if (! class_exists('DbTools', false)) {
            AliasLoader::getInstance()->alias('DbTools', DbToolsFacade::class);
        }
    }

    /**
     * Runs inside the base's boot(), after the package's own resources are registered.
     */
    #[Override]
    public function packageBooted(): void
    {
        $this->registerEventListeners();
        $this->registerDestructiveCommandGuard();
        $this->registerSchemaReadinessMiddleware();

        if ($this->app->runningInConsole()) {
            // The migration is a .stub stamped with a fresh timestamp at publish time, so it stays a
            // hand-written publishes() rather than hasMigrations(). Only the tag is namespaced.
            $this->publishes([
                $this->packagePath('database/migrations/0001_01_01_000000_create_soft_delete_history_table.php.stub') => database_path('migrations/' . date('Y_m_d_His') . '_create_soft_delete_history_table.php'),
            ], $this->package->getNamespacedPublishTag('migrations'));
        }

        // Keep the custom BlueprintMacros builder aligned with the configured
        // key type so its id()/foreignId()/morphs() overrides match the macros.
        BlueprintMacros::setIdTypeResolver(static fn (): string => ConfiguredMorphsMacro::idType());

        // Opt-in: bind BlueprintMacros over Laravel's Blueprint so the
        // overrides actually run. Laravel's schema builder resolves every
        // blueprint through Container::make(Blueprint::class, ...) when no
        // resolver is set (Schema\Builder::createBlueprint()), so the binding
        // reaches every connection with nothing else to wire.
        //
        // Not Schema::blueprintResolver(): Connection::getSchemaBuilder()
        // returns a new builder on each call, so a resolver set on one instance
        // is lost on the next.
        if ((bool) config('laranail.db-tools.schema.blueprint_macros', false)) {
            $this->app->bind(IlluminateBlueprint::class, BlueprintMacros::class);
        }

        AuditColumnsMacro::register();
        SoftDeletesWithUndoMacro::register();
        ConfiguredMorphsMacro::register();
        SoftDeleteHistoryMacro::register();
        FieldGroupMacros::register();
    }

    /**
     * Make the schema-readiness middleware available, and (when enabled)
     * auto-register it.
     *
     * It is pushed onto the HTTP kernel's GLOBAL stack rather than the web/api
     * route groups: a traditional kernel rebuilds its route groups per request
     * (syncMiddlewareToRouter), which would drop a boot-time group append, whereas
     * the global stack persists across both the slim and traditional kernels. The
     * middleware only stamps advisory headers, so running it globally is safe.
     * Opt out via `laranail.db-tools.readiness.middleware.enabled = false`, or
     * register the `laranail-db-tools.schema-ready` alias manually.
     */
    private function registerSchemaReadinessMiddleware(): void
    {
        $router = $this->app->make('router');

        if ($router instanceof Router) {
            $router->aliasMiddleware('laranail-db-tools.schema-ready', EnsureSchemaIsReady::class);
        }

        if (! (bool) $this->app->make('config')->get('laranail.db-tools.readiness.middleware.enabled', true)) {
            return;
        }

        try {
            $kernel = $this->app->make(HttpKernelContract::class);

            if (method_exists($kernel, 'hasMiddleware') && $kernel->hasMiddleware(EnsureSchemaIsReady::class)) {
                return;
            }

            if (method_exists($kernel, 'pushMiddleware')) {
                $kernel->pushMiddleware(EnsureSchemaIsReady::class);
            }
        } catch (Throwable) {
            // No HTTP kernel (e.g. a pure console/queue context) — nothing to guard.
        }
    }

    /**
     * Wire the default log listener for the availability/readiness events.
     * Opt-out via `db-tools.guard.log_events`; apps can always listen directly.
     */
    /**
     * Guard the two commands that drop tables without running any migration's
     * `down()`.
     *
     * `migrate:rollback` and `migrate:reset` both reach `Migrator::runDown()`,
     * so a BaseMigration guard already covers them. `migrate:fresh` and
     * `db:wipe` go straight to the schema builder and cannot be caught that
     * way — which is what this is for.
     */
    private function registerDestructiveCommandGuard(): void
    {
        if (! (bool) $this->app->make('config')->get('laranail.db-tools.migrations.guard_destructive_commands', true)) {
            return;
        }

        $this->app->make('events')->listen(
            CommandStarting::class,
            [GuardsDestructiveCommands::class, 'handle'],
        );
    }

    private function registerEventListeners(): void
    {
        if (! (bool) $this->app->make('config')->get('laranail.db-tools.guard.log_events', true)) {
            return;
        }

        $events = $this->app->make('events');
        $events->listen(DatabaseUnavailable::class, [LogDatabaseIssues::class, 'handleDatabaseUnavailable']);
        $events->listen(SchemaNotReady::class, [LogDatabaseIssues::class, 'handleSchemaNotReady']);
    }
}
