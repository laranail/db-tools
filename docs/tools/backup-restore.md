# Backup & restore

`Backup\BackupManager` (bound to `Backup\Contracts\BackupManagerInterface`)
coordinates database backups using a driver pattern, and restores from `.sql`
files via `Backup\SqlFileRestorer`.

```php
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupManagerInterface;

$manager = app(BackupManagerInterface::class);
// or: Simtabi\Laranail\DbTools\DbTools::backup(...)
```

## How drivers are resolved

On construction `BackupManager` registers the three default drivers
(`MysqlBackupDriver`, `PostgresBackupDriver`, `SqliteBackupDriver`). When you
call `backup()`, the manager reads the connection's config, resolves its `url`
the way Laravel does before opening PDO (see
[Connection URLs](#connection-urls-and-managed-databases)), takes the resulting
`driver` value, and asks each registered driver `supports($driver)` until one
matches. If none match it throws a `RuntimeException`.

```mermaid
flowchart LR
    A["backup(path, connection)"] --> B["read database.connections.{conn}"]
    B --> U["resolve url (ConfigurationUrlParser)"]
    U --> C{"config.driver"}
    C -->|mysql / mariadb| MY["MysqlBackupDriver -> mysqldump"]
    C -->|pgsql| PG["PostgresBackupDriver -> pg_dump"]
    C -->|sqlite| SQ["SqliteBackupDriver -> file copy"]
    C -->|other| ERR["RuntimeException: no driver"]
```

`supportsDriver(string $driver)` reports whether any registered driver matches a
driver name without performing a backup:

```php
$manager->supportsDriver('pgsql'); // true
$manager->supportsDriver('oracle'); // false
```

## Creating a backup

```php
$manager->backup(storage_path('backups/dump.sql'));            // default connection
$manager->backup(storage_path('backups/pg.dump'), 'pgsql');    // named connection
```

### MySQL / MariaDB

Shells out to `mysqldump`. The password goes into a temporary option file with
mode `0600`, passed as `--defaults-extra-file` and deleted as soon as the client
exits — never on the command line, and no longer through the deprecated
`MYSQL_PWD` variable. The connection config must contain `host`, `username`,
`password`, and `database` (directly or through `url`); `port` (default `3306`)
and `socket` are optional. Writes via `--result-file`.

Every dump runs with:

| Flag | Why |
|---|---|
| `--single-transaction` | A consistent InnoDB snapshot without `LOCK TABLES`. |
| `--no-tablespaces` | Tablespace statements need `PROCESS`, which managed users lack. |
| `--routines --triggers` | Stored routines and triggers come with the schema. |
| `--set-gtid-purged=OFF` | Only when the client's `--help` lists it (MySQL's does, MariaDB's does not). A GTID server otherwise writes `SET @@GLOBAL.GTID_PURGED`, which needs `SUPER` to replay. |

### PostgreSQL

Shells out to `pg_dump` with `--format=custom --no-password --no-owner
--no-privileges`, so the archive names no roles the target may not have. The
password is passed via the `PGPASSWORD` environment variable. Requires `host`,
`username`, `password`, `database` (directly or through `url`); `port` (default
`5432`) and `schema` are optional. Writes via `--file`.

Before `pg_dump` or `pg_restore` runs, the driver compares the client's major
version (`--version`) with the server's (`SHOW server_version_num`, through
`psql`) and throws when the client is older: `pg_dump` 15 cannot dump a
PostgreSQL 18 server. The message names both versions and the
`backup.binaries.*` key to point at a newer client. If either version cannot be
read, the check steps aside and the tool reports what stopped it.

## Connection URLs and managed databases

A managed provider (DigitalOcean, RDS, Aiven, …) hands out one connection URL
and requires TLS. The backup drivers read the connection the way the application
does:

- **`url` wins.** The config passes through Laravel's `ConfigurationUrlParser`,
  so `url` overrides `host`, `port`, `database`, `username` and `password`, its
  scheme picks the driver, and query parameters (`?sslmode=require`,
  `?ssl-mode=REQUIRED`) become config keys.
- **MySQL TLS** comes from the same PDO options the application connects with:

  | Source | Client flag |
  |---|---|
  | `Pdo\Mysql::ATTR_SSL_CA` (or `ssl-ca`) | `--ssl-ca` |
  | `Pdo\Mysql::ATTR_SSL_CAPATH` | `--ssl-capath` |
  | `Pdo\Mysql::ATTR_SSL_CERT` | `--ssl-cert` |
  | `Pdo\Mysql::ATTR_SSL_KEY` | `--ssl-key` |
  | `Pdo\Mysql::ATTR_SSL_CIPHER` | `--ssl-cipher` |
  | `ssl-mode` / `sslmode` | `--ssl-mode` |

  Without an explicit mode, a CA means `--ssl-mode=VERIFY_IDENTITY`, as mysqlnd
  verifies by default; `ATTR_SSL_VERIFY_SERVER_CERT => false` means `REQUIRED`
  without the CA. libpq spellings (`require`, `verify-full`, …) are accepted, and
  an unrecognised mode throws rather than falling back to plaintext. MariaDB's
  client, which has no `--ssl-mode`, gets `--ssl` and `--ssl-verify-server-cert`.
- **PostgreSQL TLS** goes to libpq through the environment: `sslmode`,
  `sslrootcert`, `sslcert` and `sslkey` become `PGSSLMODE`, `PGSSLROOTCERT`,
  `PGSSLCERT` and `PGSSLKEY` — the same keys Laravel puts in the PDO DSN.

```php
// config/database.php — DB_URL=postgresql://doadmin:…@db-….ondigitalocean.com:25060/defaultdb?sslmode=require
'pgsql' => [
    'driver'      => 'pgsql',
    'url'         => env('DB_URL'),
    'sslrootcert' => env('DB_SSLROOTCERT'), // the provider's CA certificate
    // …
],
```

> A provider's own scheduled backups and point-in-time recovery are the primary
> backup on a managed cluster. A dump from here is a portable copy, not a
> replacement for them.

### gzip, excluded tables, and binary paths

The MySQL and PostgreSQL drivers honour the
[`db-tools.backup`](../configuration.md#backup) config:

- `backup.gzip` — when `true`, the dump is gzip-compressed and the driver
  appends `.gz` to the path.
- `backup.exclude` — table names omitted from the dump.
- `backup.binaries.*` — absolute paths to the CLI tools (`mysqldump`, `mysql`,
  `pg_dump`, `pg_restore`, `psql`) when they are not on `PATH`; `null` means
  "rely on `PATH`".

### SQLite

Copies the database file to the backup path (creating the target directory if
needed) and, when present, copies the `-wal` and `-shm` sidecar files too.
Requires the `database` config path. Backing up a `:memory:` database throws a
`RuntimeException`.

## Restoring

```php
$manager->restore(storage_path('backups/dump.sql'));
$manager->restore(storage_path('backups/pg.dump'), 'pgsql');
```

`restore()` is **driver-aware**: it resolves the connection's driver and
delegates to that driver, so the restore mechanism matches the dump format.

- **PostgreSQL custom-format dumps** restore through `pg_restore` (not the
  SQL-text path) with `--clean --if-exists --no-owner --no-privileges
  --single-transaction --exit-on-error`: objects belong to the restoring user, and
  a failure rolls the whole restore back.
- **MySQL** streams the dump through the `mysql` client line by line, so a large
  dump is never held in memory, and strips `DEFINER=user@host` clauses on the
  way: only `SUPER` may create an object owned by another account (error 1227 on
  a managed server). `INSERT` lines are passed through untouched, so row data
  that happens to contain the text is never rewritten.
- **SQLite** uses `SqlFileRestorer` for a plain `.sql` file, or a file copy
  otherwise.

For plain `.sql` text, `SqlFileRestorer`:

1. Validates the file exists, is readable, and is non-empty (else
   `InvalidArgumentException`).
2. Strips single-line (`-- …`) and block (`/* … */`) comments, then splits the
   SQL into statements on `;`, respecting quoted string literals and escaped
   quotes.
3. Executes every statement inside a single transaction (via the
   `ManagesTransactions` trait). A failing statement rolls back the whole
   restore and surfaces a `RuntimeException`.

Gzipped dumps and the optional `pg_restore` / `mysql` / `psql` binary paths from
[`db-tools.backup`](../configuration.md#backup) are honoured on restore
too.

## Importing a dump file

`Files\DatabaseFileService::handleImport()` is a thin entry point for importing
a backup file. It checks the extension, resolves the real path, confines it to
the configured import directory, then delegates to the driver-aware `restore()`
above — no shell calls happen in the service itself.

The import directory comes from
[`db-tools.files.import_base`](../configuration.md), which defaults to
`storage_path('app')`. Set it to `null` to allow imports from anywhere.

> Before 0.6.0 the docblock claimed path-traversal protection but only called
> `realpath()`, which **canonicalises** a path rather than rejecting it — so any
> readable `.sql` anywhere on the filesystem was importable.

```php
use Simtabi\Laranail\DbTools\Files\Contracts\DatabaseFileServiceInterface;

app(DatabaseFileServiceInterface::class)
    ->handleImport(storage_path('backups/dump.sql'));        // default connection
    // ->handleImport($path, 'pgsql');                       // named connection
```

Only `.sql` and `.dump` files are importable. A live SQLite database file
(`.sqlite` / `.db`) is intentionally refused — importing it would mean swapping
the running database file in place, which is out of scope for a dump import;
`handleImport()` throws a `RuntimeException` for those.

## Custom drivers

`registerDriver()` appends a driver implementing `BackupDriverInterface`
(`backup(array $config, string $path): bool` + `supports(string $driver): bool`)
and returns `$this`, so you can register additional or override database
support.

```php
$manager->registerDriver(new MyCustomBackupDriver());
```

## Reference

```php
// BackupManagerInterface
public function backup(string $path, ?string $connection = null): bool;
public function restore(string $path, ?string $connection = null): bool;
public function supportsDriver(string $driver): bool;

// BackupManager (concrete)
public function registerDriver(BackupDriverInterface $driver): static;

// BackupDriverInterface
public function backup(array $config, string $path): bool;
public function supports(string $driver): bool;
```

---
[← Docs index](../../README.md#documentation)
