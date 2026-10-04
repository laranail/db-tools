# Getting started

Install `laranail/db-tools`, optionally publish its config, then make your first connection check,
schema query and backup through the `DbTools` class. For the full reference see the
[Documentation index](../README.md#documentation).

## 1. Install

```bash
composer require laranail/db-tools
```

The service provider and the `DbTools` facade alias are auto-discovered, so nothing needs registering
by hand. Requirements and what the provider binds are in [Installation](installation.md).

## 2. Configure (optional)

The defaults work as installed. Publish the config when you want to change them:

```bash
php artisan vendor:publish --tag=laranail::db-tools-config
```

It lands at `config/laranail/db-tools.php` and is read under the `laranail.db-tools` key, for example
`config('laranail.db-tools.id_type')` (env `DB_TOOLS_ID_TYPE`, default `BIGINT`) or
`config('laranail.db-tools.money.default_currency')` (env `DB_TOOLS_MONEY_CURRENCY`, default `USD`).
Every key is listed in [Configuration](configuration.md).

If you use the soft-delete restore history, publish its migration and run it:

```bash
php artisan vendor:publish --tag=laranail::db-tools-migrations
php artisan migrate
```

## 3. Check the connection and the schema

`Simtabi\Laranail\DbTools\DbTools` is a static entry point; every method takes an optional connection
name and uses the default connection when it is omitted.

```php
use Simtabi\Laranail\DbTools\DbTools;

DbTools::testConnection();                         // is the connection live?
DbTools::getDriver();                              // 'mysql', 'pgsql', 'sqlite', ...
DbTools::tables();                                 // list tables
DbTools::hasTable('users');                        // does a table exist?
DbTools::getMissingTables(['users', 'invoices']);  // ['invoices']
```

For methods the facade does not surface, reach the underlying service:

```php
DbTools::schemaInspector()->hasColumns('users', ['email', 'name']);
```

To ask whether the schema is ready to serve requests from the command line:

```bash
php artisan laranail::db-tools.health --strict
```

## 4. Back up and restore

```php
DbTools::backup(storage_path('backups/dump.sql'));   // driver-aware dump
DbTools::restore(storage_path('backups/dump.sql'));  // restore from a .sql file
```

Or from the CLI, with `export`, `import`, `restore` or `clean` as the action:

```bash
php artisan laranail::db-tools.db export --path=storage/backups/dump.sql
php artisan laranail::db-tools.db restore --path=storage/backups/dump.sql
```

## Next steps

- [Facade](tools/facade.md) — every `DbTools` method with examples.
- [Backup & restore](tools/backup-restore.md) — per-driver backups and restore.
- [Database CLI](tools/database-cli.md) — every `laranail::db-tools.db` action and option.
- [Schema macros](tools/macros.md) — `auditColumns()`, `softDeletesWithUndo()`, and the rest.
- [Configuration](configuration.md) — every config key.

---

[← Docs index](../README.md#documentation)
