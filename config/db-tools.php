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

];
