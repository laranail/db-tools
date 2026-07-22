<?php

declare(strict_types=1);

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
    | Availability guard
    |--------------------------------------------------------------------------
    |
    | The boot-safe DatabaseGuard memoizes each connection's availability probe
    | for the lifetime of the guard instance (per request / command). Disable to
    | re-probe on every call.
    |
    */

    'guard' => [
        'memoize' => env('DB_TOOLS_GUARD_MEMOIZE', true),
    ],

];
