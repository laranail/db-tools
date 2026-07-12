# The `DbTools` facade

`Simtabi\Laranail\DbTools\DbTools` is the unified, static entry
point for connection testing, schema inspection, table verification, and
backup. Every method delegates to a container-bound service contract.

Every method that accepts a `?string $connection` uses the default connection
when it is `null`.

```php
use Simtabi\Laranail\DbTools\DbTools;
```

## Connection testing

### `testConnection(?string $connection = null): bool`

Returns whether the connection can open a PDO handle.

```php
if (! DbTools::testConnection()) {
    abort(503, 'Database is unreachable.');
}
```

### `getConnectionInfo(?string $connection = null): array`

Detailed connection report (success flag, message, driver, version, database).
See [connection-testing.md](connection-testing.md) for the array shape.

```php
$info = DbTools::getConnectionInfo('pgsql');
```

### `getDriver(?string $connection = null): string`

The driver name (`mysql`, `pgsql`, `sqlite`, …), or `'unknown'` on failure.

```php
DbTools::getDriver(); // 'sqlite'
```

### `getVersion(?string $connection = null): ?string`

The server version string, or `null` if it cannot be determined.

```php
DbTools::getVersion(); // '8.0.36' (MySQL), '3.45.1' (SQLite), …
```

### `getDatabaseName(?string $connection = null): ?string`

The active database name, or `null` on failure.

```php
DbTools::getDatabaseName(); // 'forge'
```

## Schema inspection

### `tables(?string $connection = null): array`

List of table names. Returns `[]` on failure.

```php
$tables = DbTools::tables();
```

### `hasTable(string $table, ?string $connection = null): bool`

```php
DbTools::hasTable('users'); // true
```

### `tableCount(?string $connection = null): int`

Number of tables (driver-aware count). Returns `0` on failure.

```php
DbTools::tableCount(); // 14
```

### `columns(string $table, ?string $connection = null): array`

Column names for a table. Returns `[]` on failure.

```php
DbTools::columns('users'); // ['id', 'name', 'email', …]
```

### `hasColumn(string $table, string $column, ?string $connection = null): bool`

```php
DbTools::hasColumn('users', 'email'); // true
```

## Table verification

### `verifyTables(array $tables, bool $requireAll = false, ?string $connection = null): bool`

`requireAll = false` passes if *any* table exists; `true` requires *all*.

```php
DbTools::verifyTables(['users', 'orders'], requireAll: true);
```

### `verifyTablesDetailed(array $tables, bool $requireAll = false, ?string $connection = null): array`

Detailed verification (always tests the connection first). See
[table-verification.md](table-verification.md) for the result shape.

```php
$report = DbTools::verifyTablesDetailed(['users', 'orders']);
```

### `hasLaravelTables(bool $strict = false, ?string $connection = null): bool`

Checks the default Laravel tables (`migrations`, `users`,
`password_reset_tokens`, `failed_jobs`). `strict = true` requires all of them.

```php
DbTools::hasLaravelTables(); // any present
DbTools::hasLaravelTables(strict: true); // all present
```

### `getMissingTables(array $tables, ?string $connection = null): array`

Subset of `$tables` that do not exist.

```php
DbTools::getMissingTables(['users', 'invoices']); // ['invoices']
```

## Backup operations

### `backup(string $path, ?string $connection = null): bool`

Create a backup at `$path`, picking the driver from the connection's driver
name.

```php
DbTools::backup(storage_path('backups/dump.sql'));
```

### `restore(string $path, ?string $connection = null): bool`

Restore from a `.sql` file (via `SqlFileRestorer`, inside a transaction).

```php
DbTools::restore(storage_path('backups/dump.sql'));
```

### `supportsBackupDriver(string $driver): bool`

Whether any registered backup driver supports the given database driver name.

```php
DbTools::supportsBackupDriver('pgsql'); // true
```

See [backup-restore.md](backup-restore.md) for per-driver detail.

## Foreign keys

### `withoutForeignKeyChecks(Closure $callback): mixed`

Runs the callback with foreign key constraints disabled (driver-aware, via
Laravel's `Schema::withoutForeignKeyConstraints()`). Returns the callback's
return value.

```php
DbTools::withoutForeignKeyChecks(function (): void {
    User::truncate();
    Post::truncate();
});
```

> For nesting-aware control on a single instance, see the
> [`ManagesForeignKeyChecks`](traits.md#managesforeignkeychecks) trait.

## Service accessors

These return the underlying service instances from the container, for when you
need methods not surfaced on the facade.

| Method | Returns |
|--------|---------|
| `connectionTester()` | `DatabaseConnectionTesterInterface` |
| `schemaInspector()` | `DatabaseSchemaInspectorInterface` |
| `tableVerifier()` | `DatabaseTableVerifierInterface` |
| `backupManager()` | `BackupManagerInterface` |

```php
// e.g. hasColumns() lives on the inspector, not the facade:
DbTools::schemaInspector()->hasColumns('users', ['email', 'name']);
```

---
[← Docs index](../../README.md#documentation)
