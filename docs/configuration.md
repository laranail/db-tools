# Configuration

`laranail/db-tools` ships a publishable config file and a publishable
migration. Both are optional — every key has a sensible default and the package
works out of the box — but publishing them lets you tune the primary-key type,
audit column names, money currency, backup behavior, and the soft-delete
history table.

## The config namespace

Per the laranail convention, this package's config is namespaced under
`laranail`: read every value as **`config('laranail.db-tools.*')`** (e.g.
`config('laranail.db-tools.guard.probe_timeout')`). The provider
`mergeConfigFrom()`s the package default under that key, so it resolves whether
or not you publish.

> Migrated in v0.4.0 from the flat `config('db-tools.*')` key. If you are
> upgrading from ≤ 0.3, update your `config()` calls and any published file
> location (below).

## Publishing the config

```bash
php artisan vendor:publish --tag=laranail::db-tools-config
```

This copies the package default to **`config/laranail/db-tools.php`** in your
app. Laravel loads nested config directories, so a file at
`config/laranail/db-tools.php` is exposed as `config('laranail.db-tools.*')` —
matching the merged default. Unpublished keys still resolve via
`mergeConfigFrom()`.

## Publishing the soft-delete history migration

The [soft-delete restore history](tools/soft-deletes.md) feature needs a history
table. Publish its migration with:

```bash
php artisan vendor:publish --tag=laranail::db-tools-migrations
php artisan migrate
```

The stub uses the [`softDeleteHistory()`](tools/macros.md#softdeletehistory)
macro, so the table's polymorphic columns match your configured `id_type`.

## Keys

### `id_type`

```php
'id_type' => env('DB_TOOLS_ID_TYPE', 'BIGINT'),
'using_uuids_for_id' => false,
'using_ulids_for_id' => false,
```

The primary-key type for the package's key-aware features. One of `BIGINT`,
`UUID`, or `ULID`. Drives:

- the [`HasUuidsOrIntegerIds`](tools/traits.md) model trait,
- the `BlueprintMacros` `id()` / `foreignId()` / `morphs()` overrides,
- the [`configuredMorphs()` / `configuredNullableMorphs()`](tools/macros.md#configuredmorphs) macros,
- and the polymorphic columns in the soft-delete history table.

The two boolean flags are convenience shortcuts: when either is `true` it takes
precedence over `id_type` (UUID wins over ULID). Set `id_type` via the
`DB_TOOLS_ID_TYPE` env var.

### `audit`

```php
'audit' => [
    'created_by' => 'created_by',
    'updated_by' => 'updated_by',
    'deleted_by' => 'deleted_by',
],
```

Column names stamped by the [`AuditObserver`](tools/observers.md). Rename them
here if your schema uses different conventions. They must be nullable so guest
and console writes (no authenticated user) succeed.

### `money`

```php
'money' => [
    'default_currency' => env('DB_TOOLS_MONEY_CURRENCY', 'USD'),
],
```

The ISO 4217 currency [`CastMoney`](tools/casts.md#castmoney) uses when a column
does not supply one through a cast argument. See the casts page for the cast
argument syntax (`CastMoney::class.':EUR'`).

### `backup`

```php
'backup' => [
    'gzip' => false,
    'exclude' => [],
    'binaries' => [
        'mysqldump'  => null,
        'mysql'      => null,
        'pg_dump'    => null,
        'pg_restore' => null,
        'psql'       => null,
    ],
],
```

- `gzip` — when `true`, drivers gzip their dumps and append `.gz`.
- `exclude` — table names omitted from dumps.
- `binaries` — absolute paths to the CLI tools when they are not on `PATH`;
  `null` means "rely on `PATH`".

See [Backup & restore](tools/backup-restore.md) for how these are applied.

### `soft_delete_history`

```php
'soft_delete_history' => [
    'table' => 'soft_delete_history',
],
```

The table name used by [`HasSoftDeletesWithUndo`](tools/soft-deletes.md) and the
`softDeleteHistory()` macro. Change it here and the trait, macro, and published
migration all follow.

### `files`

```php
'files' => [
    'import_base' => env('DB_TOOLS_IMPORT_BASE', storage_path('app')),
],
```

The directory `Files\DatabaseFileService::handleImport()` will import from.
Files resolving outside it are rejected with a `RuntimeException`.

Set it to `null` to disable confinement, which is the pre-0.6.0 behaviour — but
note that without it any readable file on the filesystem is importable, which
matters wherever the path can come from a request. The docblock previously
claimed path-traversal protection while only calling `realpath()`, which
canonicalises a path rather than rejecting it.

### `migrations`

```php
'migrations' => [
    'reversible_environments' => ['local', 'development', 'dev', 'testing'],
    'allow_rollback' => env('DB_TOOLS_ALLOW_ROLLBACK', false),
    'guard_destructive_commands' => true,
],
```

Whether this installation may have its schema dropped — see
[Migrations](tools/migrations.md). `reversible_environments` is where dropping
is normal workflow (`testing` is in the list because `RefreshDatabase` runs
`migrate:fresh`), `allow_rollback` is the deliberate override for an operator
who means it, and `guard_destructive_commands` registers the listener that
applies the same policy to `migrate:fresh` and `db:wipe` — the two commands
that drop every table without running any migration's `down()`.

The override is read through `config()` and never `env()`: `config:cache` is
routine on exactly the servers where this matters, and `env()` returns null once
configuration is cached, which would shut the escape hatch for the one operator
who needs it.

---
[← Docs index](../README.md#documentation)
