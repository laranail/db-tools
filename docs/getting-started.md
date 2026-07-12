# Getting started

Install `laranail/db-tools` and make your first schema-inspection and backup calls through the
`DbTools` facade. For the full reference see the [Documentation index](../README.md#documentation).

## 1. Install

```bash
composer require laranail/db-tools
```

The service provider + the `DbTools` facade are auto-discovered. Publish the config (and the history
migration) if you want to customise them:

```bash
php artisan vendor:publish --tag=db-tools-config
```

## 2. Inspect + verify schema

```php
use Simtabi\Laranail\DbTools\Facades\DbTools;

DbTools::connection()->test();          // is the connection live?
DbTools::schema()->tables();            // list tables
DbTools::verify()->exists('users');     // does a table exist?
```

## 3. Back up + restore

```php
DbTools::backup()->run();               // driver-aware dump
DbTools::backup()->restore($path);      // restore from a dump
```

Or from the CLI: `php artisan laranail::db-tools.db export|import|restore|clean`.

## Next steps

- [Facade](tools/facade.md) — every `DbTools` method with examples.
- [Backup & restore](tools/backup-restore.md) — per-driver backups + restore.
- [Schema macros](tools/macros.md) — `auditColumns()`, `softDeletesWithUndo()`, …
- [Configuration](configuration.md) — every config key.

---

[← Docs index](../README.md#documentation)
