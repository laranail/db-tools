# Schema inspection

`Schema\DatabaseSchemaInspector` (bound to
`Schema\Contracts\DatabaseSchemaInspectorInterface`) queries tables and columns
across drivers. Like the connection tester, every method is failure-safe: on
error it logs a warning and returns an empty/false/zero fallback.

```php
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseSchemaInspectorInterface;

$inspector = app(DatabaseSchemaInspectorInterface::class);
```

## `getTables()`

```php
$inspector->getTables();         // ['migrations', 'users', 'orders', …]
$inspector->getTables('pgsql');  // named connection
```

Uses the schema builder's `getTableListing()`. Returns `[]` on failure.

## `hasTable()`

```php
$inspector->hasTable('users'); // true
```

## `getTableCount()`

```php
$inspector->getTableCount(); // 14
```

Driver-aware count: `information_schema.tables` for MySQL/MariaDB/Postgres/SQL
Server (Postgres scopes to `database.connections.pgsql.schema`, default
`public`), and `sqlite_master` (excluding internal `sqlite_%` tables) for
SQLite. Returns `0` for unknown drivers or on error.

## `getColumns()`

```php
$inspector->getColumns('users'); // ['id', 'name', 'email', 'created_at', …]
```

Returns `[]` on failure.

## `hasColumn()`

```php
$inspector->hasColumn('users', 'email'); // true
```

## `hasColumns()`

Checks that **all** given columns exist (not surfaced on the facade — use the
service accessor).

```php
$inspector->hasColumns('users', ['email', 'name']); // true
```

## Reference

```php
public function getTables(?string $connection = null): array;
public function hasTable(string $table, ?string $connection = null): bool;
public function getTableCount(?string $connection = null): int;
public function getColumns(string $table, ?string $connection = null): array;
public function hasColumn(string $table, string $column, ?string $connection = null): bool;
public function hasColumns(string $table, array $columns, ?string $connection = null): bool;
```

---
[← Docs index](../../README.md#documentation)
