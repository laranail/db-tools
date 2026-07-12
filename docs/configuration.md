# Configuration

`laranail/db-tools` ships a publishable config file and a publishable
migration. Both are optional — every key has a sensible default and the package
works out of the box — but publishing them lets you tune the primary-key type,
audit column names, money currency, backup behavior, and the soft-delete
history table.

## Publishing the config

```bash
php artisan vendor:publish --tag=db-tools-config
```

This copies `config/db-tools.php` into your app's `config/` directory.
The provider already `mergeConfigFrom()`s the package default, so unpublished
keys still resolve.

## Publishing the soft-delete history migration

The [soft-delete restore history](tools/soft-deletes.md) feature needs a history
table. Publish its migration with:

```bash
php artisan vendor:publish --tag=db-tools-migrations
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

---
[← Docs index](../README.md#documentation)
