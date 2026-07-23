<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Providers;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Override;
use Psr\Log\LoggerInterface;
use Simtabi\Laranail\DbTools\Backup\BackupManager;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupManagerInterface;
use Simtabi\Laranail\DbTools\Console\DbToolsCommand;
use Simtabi\Laranail\DbTools\Console\HealthCommand;
use Simtabi\Laranail\DbTools\DbTools;
use Simtabi\Laranail\DbTools\Events\DatabaseUnavailable;
use Simtabi\Laranail\DbTools\Events\SchemaNotReady;
use Simtabi\Laranail\DbTools\Files\Contracts\DatabaseFileServiceInterface;
use Simtabi\Laranail\DbTools\Files\DatabaseFileService;
use Simtabi\Laranail\DbTools\Guard\Contracts\DatabaseAvailabilityInterface;
use Simtabi\Laranail\DbTools\Guard\DatabaseGuard;
use Simtabi\Laranail\DbTools\Listeners\LogDatabaseIssues;
use Simtabi\Laranail\DbTools\Schema\AuditColumnsMacro;
use Simtabi\Laranail\DbTools\Schema\BlueprintMacros;
use Simtabi\Laranail\DbTools\Schema\ConfiguredMorphsMacro;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseConnectionTesterInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseSchemaInspectorInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseTableVerifierInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;
use Simtabi\Laranail\DbTools\Schema\DatabaseConnectionTester;
use Simtabi\Laranail\DbTools\Schema\DatabaseSchemaInspector;
use Simtabi\Laranail\DbTools\Schema\DatabaseTableVerifier;
use Simtabi\Laranail\DbTools\Schema\FieldGroupMacros;
use Simtabi\Laranail\DbTools\Schema\SchemaReadiness;
use Simtabi\Laranail\DbTools\Schema\SoftDeleteHistoryMacro;
use Simtabi\Laranail\DbTools\Schema\SoftDeletesWithUndoMacro;
use Simtabi\Laranail\DbTools\Services\Contracts\DatabaseServiceInterface;
use Simtabi\Laranail\DbTools\Services\Contracts\MaintenanceServiceInterface;
use Simtabi\Laranail\DbTools\Services\DatabaseService;
use Simtabi\Laranail\DbTools\Services\MaintenanceService;

final class DbToolsServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/db-tools.php', 'db-tools');

        // Backup
        $this->app->singleton(BackupManagerInterface::class, BackupManager::class);

        // Files
        $this->app->singleton(DatabaseFileServiceInterface::class, DatabaseFileService::class);

        // Schema / Inspection
        $this->app->singleton(DatabaseSchemaInspectorInterface::class, DatabaseSchemaInspector::class);
        $this->app->singleton(DatabaseTableVerifierInterface::class, DatabaseTableVerifier::class);
        $this->app->singleton(DatabaseConnectionTesterInterface::class, DatabaseConnectionTester::class);

        // Boot-safe availability guard (never-throws), layered on the tester + inspector.
        $this->app->singleton(DatabaseAvailabilityInterface::class, fn ($app): DatabaseGuard => new DatabaseGuard(
            $app->make(DatabaseConnectionTesterInterface::class),
            $app->make(DatabaseSchemaInspectorInterface::class),
            (bool) $app->make('config')->get('db-tools.guard.memoize', true),
            (bool) $app->make('config')->get('db-tools.guard.emit_events', true),
        ));

        // Boot-safe schema-readiness reporter (reachable | migrated | ready).
        $this->app->singleton(SchemaReadinessInterface::class, fn ($app): SchemaReadiness => new SchemaReadiness(
            $app->make(DatabaseAvailabilityInterface::class),
            $app->make(DatabaseTableVerifierInterface::class),
            (bool) $app->make('config')->get('db-tools.guard.emit_events', true),
        ));

        // General DB service
        $this->app->singleton(DatabaseServiceInterface::class, DatabaseService::class);

        // Filesystem maintenance (caches, logs, storage symlink)
        $this->app->singleton(MaintenanceServiceInterface::class, fn ($app): MaintenanceService => new MaintenanceService(
            $app->make(LoggerInterface::class),
            $app->basePath(),
        ));

        if (class_exists('DbTools')) {
            AliasLoader::getInstance()->alias('DbTools', DbTools::class);
        }
    }

    public function boot(): void
    {
        $this->registerEventListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([
                DbToolsCommand::class,
                HealthCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../../config/db-tools.php' => config_path('db-tools.php'),
            ], 'db-tools-config');

            $this->publishes([
                __DIR__.'/../../database/migrations/0001_01_01_000000_create_soft_delete_history_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_soft_delete_history_table.php'),
            ], 'db-tools-migrations');
        }

        // Keep the custom BlueprintMacros builder aligned with the configured
        // key type so its id()/foreignId()/morphs() overrides match the macros.
        BlueprintMacros::setIdTypeResolver(static fn (): string => ConfiguredMorphsMacro::idType());

        AuditColumnsMacro::register();
        SoftDeletesWithUndoMacro::register();
        ConfiguredMorphsMacro::register();
        SoftDeleteHistoryMacro::register();
        FieldGroupMacros::register();
    }

    /**
     * Wire the default log listener for the availability/readiness events.
     * Opt-out via `db-tools.guard.log_events`; apps can always listen directly.
     */
    private function registerEventListeners(): void
    {
        if (! (bool) $this->app->make('config')->get('db-tools.guard.log_events', true)) {
            return;
        }

        $events = $this->app->make('events');
        $events->listen(DatabaseUnavailable::class, [LogDatabaseIssues::class, 'handleDatabaseUnavailable']);
        $events->listen(SchemaNotReady::class, [LogDatabaseIssues::class, 'handleSchemaNotReady']);
    }
}
