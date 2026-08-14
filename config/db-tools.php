<?php

declare(strict_types=1);

/*
 * Merged under the namespaced key "laranail.db-tools" per the laranail
 * convention: read every value as config('laranail.db-tools.*'). When
 * published, this file lands at config/laranail/db-tools.php so Laravel loads
 * it under the same key.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Primary key type
    |--------------------------------------------------------------------------
    |
    | Drives HasUuidsOrIntegerIds, the BlueprintMacros id()/foreignId()/morphs()
    | overrides, and the configured-morphs, field-group and audit macros. One of:
    | BIGINT, UUID, ULID. The boolean flags take precedence over "id_type" when
    | true; "using_uuids_for_id" (UUID) wins over "using_ulids_for_id" (ULID).
    |
    */

    'id_type' => env('DB_TOOLS_ID_TYPE', 'BIGINT'),

    'using_uuids_for_id' => false,

    'using_ulids_for_id' => false,

    /*
    |--------------------------------------------------------------------------
    | Audit columns
    |--------------------------------------------------------------------------
    |
    | Column names stamped by AuditObserver / auditColumns(). They must be
    | nullable so guest and console writes (no authenticated user) succeed.
    |
    */

    'audit' => [
        'created_by' => 'created_by',
        'updated_by' => 'updated_by',
        'deleted_by' => 'deleted_by',
    ],

    /*
    |--------------------------------------------------------------------------
    | Money cast
    |--------------------------------------------------------------------------
    |
    | Default ISO 4217 currency used by CastMoney when a column does not supply
    | one via a cast argument or a paired "*_currency" column.
    |
    */

    'money' => [
        'default_currency' => env('DB_TOOLS_MONEY_CURRENCY', 'USD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Truncation guard
    |--------------------------------------------------------------------------
    |
    | Tables CleanDatabaseService will never truncate. Naming one explicitly is
    | refused; a whole-database clean skips them.
    |
    | "migrations" is here because truncating it strands the schema at an
    | unknown version with no record of how it got there, and the confirmation
    | prompt is not a guard — --force removes it, and --force is what CI uses.
    | Add anything else whose loss is not recoverable from a re-seed.
    |
    */

    'clean' => [
        'protected_tables' => ['migrations'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema blueprint
    |--------------------------------------------------------------------------
    |
    | blueprint_macros — bind BlueprintMacros over Laravel's Blueprint so that
    | id(), foreignId(), morphs() and nullableMorphs() follow "id_type" above
    | in every migration, and so registerDriverSetup() callbacks run.
    |
    | Off by default: it changes the column type every id() in the application
    | produces, which is not something a package should decide for you. The
    | *macros* (auditColumns, configuredMorphs, the field groups) are always
    | registered and need no flag.
    |
    */

    'schema' => [
        'blueprint_macros' => env('DB_TOOLS_BLUEPRINT_MACROS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup & restore
    |--------------------------------------------------------------------------
    |
    | gzip      — compress dumps with gzip (drivers append ".gz").
    | exclude   — table names omitted from dumps.
    | binaries  — optional absolute paths to the CLI tools; null = rely on PATH.
    |
    */

    'backup' => [
        'gzip' => false,
        'exclude' => [],
        'binaries' => [
            'mysqldump' => null,
            'mysql' => null,
            'pg_dump' => null,
            'pg_restore' => null,
            'psql' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft-delete restore history
    |--------------------------------------------------------------------------
    |
    | Table used by HasSoftDeletesWithUndo to record delete/restore events.
    |
    */

    'soft_delete_history' => [
        'table' => 'soft_delete_history',
    ],

    /*
    |--------------------------------------------------------------------------
    | Database file imports
    |--------------------------------------------------------------------------
    |
    | import_base confines DatabaseFileService::handleImport() to a directory.
    | realpath() alone is not a traversal check — it resolves ".." and symlinks
    | rather than rejecting them — so without a base to compare against, any
    | readable file on the filesystem is importable. That matters wherever the
    | path can come from a request.
    |
    | Set to null to disable confinement (the pre-0.6 behaviour).
    |
    */

    'files' => [
        'import_base' => env('DB_TOOLS_IMPORT_BASE', storage_path('app')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Availability guard
    |--------------------------------------------------------------------------
    |
    | The boot-safe DatabaseGuard memoizes each connection's availability probe
    | for the lifetime of the guard instance (per request / command). Disable to
    | re-probe on every call.
    |
    | probe_timeout bounds the built-in availability probe (in seconds) so an
    | unreachable or blackholed host fails fast instead of blocking for the
    | driver's default connect timeout (~30s). emit_events toggles the
    | DatabaseUnavailable / SchemaNotReady events.
    |
    */

    'guard' => [
        'memoize' => env('DB_TOOLS_GUARD_MEMOIZE', true),
        'probe_timeout' => (int) env('DB_TOOLS_GUARD_PROBE_TIMEOUT', 2),
        'emit_events' => env('DB_TOOLS_GUARD_EMIT_EVENTS', true),
        'log_events' => env('DB_TOOLS_GUARD_LOG_EVENTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema readiness
    |--------------------------------------------------------------------------
    |
    | SchemaReadiness reports whether the database is reachable, migrated, and
    | has the tables an app needs. required_tables is the default set consulted
    | when a caller does not pass its own; keep it to the framework essentials.
    |
    */

    'readiness' => [
        'required_tables' => ['migrations'],

        /*
        | The EnsureSchemaIsReady middleware. When `enabled`, the service provider
        | auto-registers it on the HTTP kernel's global stack (robust across the
        | slim and traditional kernels). It never blocks a request — it stamps the
        | advisory `header_status`/`header_message` headers when the schema is not
        | ready. `cache_store` must not depend on the database (default: file).
        */
        'middleware' => [
            'enabled' => true,
            'cache_store' => 'file',
            'cache_key' => 'db-tools.schema_ready',
            'cache_ttl' => 60,
            'header_status' => 'X-Schema-Status',
            'header_message' => 'X-Schema-Message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Boot without a database
    |--------------------------------------------------------------------------
    |
    | BootWithoutDatabase::degradeToFilesystem() swaps database-backed drivers
    | to filesystem/sync equivalents so the app can boot before its schema
    | exists (installers, first boot). The map is {config key: [from => to]}.
    |
    */

    'boot_without_database' => [
        'drivers' => [
            'session.driver' => ['database' => 'file'],
            'cache.default' => ['database' => 'file'],
            // 'queue.default' => ['database' => 'sync'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    |
    | `down()` drops tables. That is what you want while developing a schema and
    | what you never want on a live installation, where `migrate:rollback` — one
    | mistyped command, or a deploy step that runs it on failure — destroys the
    | customer's data with no confirmation and no backup.
    |
    | `reversible_environments` lists the environments where dropping the schema
    | is normal. `testing` is in the default because RefreshDatabase runs
    | migrate:fresh, and a suite that could not rebuild its schema could not run.
    |
    | `allow_rollback` is the deliberate override. Read through config() and not
    | env(), because `config:cache` is routine on exactly the servers where this
    | guard matters and env() returns null once the configuration is cached —
    | which would shut the escape hatch for the one operator who needs it.
    |
    | `guard_destructive_commands` extends the same policy to `migrate:fresh`
    | and `db:wipe`, which drop every table without running any migration's
    | down() and so cannot be caught by BaseMigration. (`migrate:rollback` and
    | `migrate:reset` both do run down(), so they need nothing here.) `--force`
    | still works: nobody types it by accident.
    |
    */

    'migrations' => [
        'reversible_environments' => ['local', 'development', 'dev', 'testing'],
        'allow_rollback' => env('DB_TOOLS_ALLOW_ROLLBACK', false),
        'guard_destructive_commands' => env('DB_TOOLS_GUARD_DESTRUCTIVE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Seeding
    |--------------------------------------------------------------------------
    |
    | Where InteractsWithSeedFiles looks for fixture files, and the locale its
    | Faker generator is built with.
    |
    | `fakerphp/faker` is a suggest, not a dependency: a production install has
    | no use for it, and the trait throws a catchable exception rather than
    | trying to install anything.
    |
    */

    'seeding' => [
        'files_path' => env('DB_TOOLS_SEED_FILES_PATH'),
        'faker_locale' => env('DB_TOOLS_FAKER_LOCALE', 'en_US'),
    ],

];
