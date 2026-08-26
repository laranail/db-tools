# Installation

Install `laranail/db-tools` and let Laravel auto-discover it.

## Requirements

- PHP `^8.4 || ^8.5`
- Laravel `^13.0` — depends only on `illuminate/database` + `illuminate/support`
- `ramsey/uuid ^4.7`, `symfony/uid ^7.0` (for UUID/ULID/NanoID traits)
- `spatie/laravel-sluggable ^3.6` (for the `HasSlug` trait)
- `brick/money ^0.10` (for the `CastMoney` cast)

## Install

```bash
composer require laranail/db-tools
```

## Auto-discovery

`Simtabi\Laranail\DbTools\Providers\DbToolsServiceProvider` is
registered automatically through Laravel's package discovery (declared under
`extra.laravel.providers` in `composer.json`). It does two things:

- **`register()`** — binds the service contracts as singletons in the container:

  | Contract | Implementation |
  |----------|----------------|
  | `Schema\Contracts\DatabaseConnectionTesterInterface` | `Schema\DatabaseConnectionTester` |
  | `Schema\Contracts\DatabaseSchemaInspectorInterface` | `Schema\DatabaseSchemaInspector` |
  | `Schema\Contracts\DatabaseTableVerifierInterface` | `Schema\DatabaseTableVerifier` |
  | `Backup\Contracts\BackupManagerInterface` | `Backup\BackupManager` |
  | `Files\Contracts\DatabaseFileServiceInterface` | `Files\DatabaseFileService` |
  | `Services\Contracts\DatabaseServiceInterface` | `Services\DatabaseService` |
  | `Services\Contracts\MaintenanceServiceInterface` | `Services\MaintenanceService` |

- **`boot()`** — registers the schema blueprint macros (`auditColumns()`,
  `softDeletesWithUndo()`, `configuredMorphs()` / `configuredNullableMorphs()`,
  `softDeleteHistory()`) and exposes the publishable config + migration.

Because the services are bound to their interfaces, you can either type-hint a
contract and let the container inject it, or reach for the `DbTools`
facade.

## Publishing config and migrations

The package works with sensible defaults, but you can publish its config to
tune the key type, audit columns, money currency, and backup behavior:

```bash
php artisan vendor:publish --tag=laranail::db-tools-config
```

The [soft-delete restore history](tools/soft-deletes.md) feature also ships a
publishable migration:

```bash
php artisan vendor:publish --tag=laranail::db-tools-migrations
php artisan migrate
```

See [Configuration](configuration.md) for every key.

## The `DbTools` facade alias

The package registers a `DbTools` alias (declared under
`extra.laravel.aliases`) pointing at
`Simtabi\Laranail\DbTools\Facades\DbToolsFacade`, whose accessor
resolves the `Simtabi\Laranail\DbTools\DbTools` class.

```php
use Simtabi\Laranail\DbTools\DbTools;

DbTools::testConnection();
DbTools::hasTable('users');
```

See [facade.md](tools/facade.md) for the full surface.

## Verify

```bash
php artisan tinker
>>> Simtabi\Laranail\DbTools\DbTools::testConnection();
=> true
```

---
[← Docs index](../README.md#documentation)
