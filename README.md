# laranail/db-tools

[![Tests](https://github.com/laranail/db-tools/actions/workflows/tests.yml/badge.svg)](https://github.com/laranail/db-tools/actions/workflows/tests.yml)
[![Static analysis](https://github.com/laranail/db-tools/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/laranail/db-tools/actions/workflows/static-analysis.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

`laranail/db-tools` is not published to Packagist, so there is no registry-version badge to show: see [Install](#install).

> Independent, framework-agnostic database utilities for Laravel — model traits (UUID/NanoID/ULID keys, JSON accessors, slugs, soft-archiving, inheritance-friendly fillable/hidden/casts/defaults), money & datetime casts, schema macros, an audit observer, backup/restore, a database CLI, cursor/offset pagination, and inspection services.

PHP `^8.4 || ^8.5` on Laravel `^13` — depends only on `illuminate/*` plus a few small libraries, with **no dependency** on other Laranail packages.

## Install

```bash
composer require laranail/db-tools
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

Full documentation is at **[opensource.simtabi.com/documentation/laranail/db-tools](https://opensource.simtabi.com/documentation/laranail/db-tools/)** — installation, getting started, the model traits, casts, schema macros, the audit observer, the migration and seeder bases, backup/restore, the database CLI, configuration, and the release process.

## Contributing & security

Issues and PRs are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities per
[SECURITY.md](SECURITY.md) (opensource@simtabi.com); participation follows the [Code of Conduct](CODE_OF_CONDUCT.md).

## License

MIT © Simtabi LLC. See [LICENSE](LICENSE).
