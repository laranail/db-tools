# laranail/db-tools

[![Tests](https://github.com/laranail/db-tools/actions/workflows/tests.yml/badge.svg)](https://github.com/laranail/db-tools/actions/workflows/tests.yml)
[![Static analysis](https://github.com/laranail/db-tools/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/laranail/db-tools/actions/workflows/static-analysis.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

`laranail/db-tools` is not published to Packagist, so there is no registry-version badge to show: see [Install](#install).

> Independent, framework-agnostic database utilities for Laravel — model traits (UUID/NanoID/ULID keys, JSON accessors, slugs, soft-archiving, inheritance-friendly fillable/hidden/casts/defaults), money & datetime casts, schema macros, an audit observer, backup/restore, a database CLI, cursor/offset pagination, and inspection services.

PHP `^8.4.1 || ^8.5` on Laravel `^13`. Its only Laranail dependency is `laranail/package-tools`, which builds its service provider.

## Install

The package is not on Packagist. Add its VCS repositories, and `laranail/package-tools`, which it requires, to your application's `composer.json` (Composer reads repositories only from the root package):

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/laranail/db-tools" },
    { "type": "vcs", "url": "https://github.com/laranail/package-tools" },
    { "type": "composer", "url": "https://repo.packagist.org", "exclude": ["laranail/*"] },
    { "packagist.org": false }
]
```

```bash
composer require laranail/db-tools:^0.1
```

`DbToolsServiceProvider` is auto-discovered and registers the schema macros at boot.

## Quick start guide and usage

### Getting started

Nothing is required: the defaults work as installed. Two optional steps:

1. Publish the config to tune the key type, audit columns, money currency and backup behaviour:

   ```bash
   php artisan vendor:publish --tag=laranail::db-tools-config
   ```

2. If you use the soft-delete restore history, publish its migration and run it:

   ```bash
   php artisan vendor:publish --tag=laranail::db-tools-migrations
   php artisan migrate
   ```

### Usage

```php
use Simtabi\Laranail\DbTools\DbTools;

DbTools::testConnection();                                   // true
DbTools::getDriver();                                        // 'pgsql'
DbTools::getMissingTables(['users', 'orders', 'invoices']);  // ['invoices']
DbTools::backup(storage_path('backups/nightly.sql'));        // true
```

The schema macros are available in any migration:

```php
Schema::create('orders', function (Blueprint $t): void {
    $t->id();
    $t->auditColumns();        // created_by, updated_by, deleted_by
    $t->softDeletesWithUndo(); // deleted_at + restored_at
    $t->timestamps();
});
```

The full API is in [The DbTools facade](docs/tools/facade.md); everything else is in the [documentation index](#documentation).

## <a name="documentation"></a>Documentation

Full documentation is at **[opensource.simtabi.com/documentation/laranail/db-tools](https://opensource.simtabi.com/documentation/laranail/db-tools/)**.

### Guides

- [Installation](docs/installation.md) — requirements, repositories and auto-discovery.
- [Getting started](docs/getting-started.md) — a first model, migration and backup.
- [Configuration](docs/configuration.md) — every key in `config/laranail/db-tools.php`.
- [Architecture](docs/architecture.md) — how the pieces fit, and why.
- [Release](docs/release.md) — versioning and the release process.

### Reference

- Models: [Base model](docs/tools/base-model.md) · [Traits](docs/tools/traits.md) · [Schema traits](docs/tools/schema-traits.md) · [Soft deletes](docs/tools/soft-deletes.md) · [Archiving](docs/tools/archiving.md) · [Casts](docs/tools/casts.md) · [Observers](docs/tools/observers.md) · [Eager loading](docs/tools/eager-loading.md) · [Pagination](docs/tools/pagination.md)
- Schema: [Macros](docs/tools/macros.md) · [Migrations](docs/tools/migrations.md) · [Seeding](docs/tools/seeding.md) · [Schema inspection](docs/tools/schema-inspection.md) · [Schema readiness](docs/tools/schema-readiness.md) · [Table verification](docs/tools/table-verification.md) · [Portable SQL](docs/tools/portable-sql.md)
- Operations: [Backup & restore](docs/tools/backup-restore.md) · [Database CLI](docs/tools/database-cli.md) · [Connection testing](docs/tools/connection-testing.md) · [Availability guard](docs/tools/availability-guard.md) · [Boot without a database](docs/tools/boot-without-database.md) · [Database session](docs/tools/database-session.md)
- API: [The DbTools facade](docs/tools/facade.md) · [Services](docs/tools/services.md) · [Events](docs/tools/events.md)

## Contributing & security

Issues and PRs are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities per
[SECURITY.md](SECURITY.md) (security@simtabi.com); participation follows the
[Code of Conduct](https://github.com/laranail/.github/blob/main/CODE_OF_CONDUCT.md).

## License

MIT © Simtabi LLC. See [LICENSE](LICENSE).
