# Schema inspection

`Schema\DatabaseSchemaInspector` (bound to
`Schema\Contracts\DatabaseSchemaInspectorInterface`) queries tables and columns
across drivers.

Listing methods are failure-safe: on error they log a warning and return an
empty list or zero. The existence checks are **not** — see
[Missing table vs unreachable database](#missing-table-vs-unreachable-database).

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

Answers `false` for a table that is not there, and **throws** when the
connection cannot be opened.

## Missing table vs unreachable database

`hasTable()`, `hasColumn()` and `hasColumns()` used to catch everything and
answer `false`, which made "this table does not exist" and "this database is
down" indistinguishable. A verification run against an unreachable database
reported every table missing, pointing the operator at migrations when the
connection was the problem.

They now probe the connection before deciding: an absent table still answers
`false`, an unreachable database rethrows the driver's own error. Callers that
want the old blanket behaviour should catch it explicitly.

`getTables()`, `getColumns()` and `getTableCount()` keep degrading to `[]` /
`0` with a logged warning.

## `getTableCount()`

```php
$inspector->getTableCount(); // 14
```

Driver-aware count: `information_schema.tables` for MySQL/MariaDB/Postgres/SQL
Server, and `sqlite_master` (excluding internal `sqlite_%` tables) for SQLite.
Returns `0` for unknown drivers or on error.

Counts **base tables only** — views are excluded on every driver, so the count
agrees with `getTables()`.

On Postgres the schema comes from the connection being counted: its
`search_path` (first entry of a comma-separated list or array), then its
`schema`, then `public`. It previously read a hardcoded
`database.connections.pgsql.schema`, so counting a connection named anything
else read the wrong schema — or silently fell back to `public` when no
connection was literally named `pgsql`.

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
