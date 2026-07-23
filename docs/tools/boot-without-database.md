# Boot without a database

Swap database-backed session/cache/queue drivers to filesystem equivalents so an application can boot before its schema exists — installers, first boot, maintenance.

## Why

With `SESSION_DRIVER=database` (or a database cache/queue), the framework reads those tables while
booting the very page an installer needs to render to create them — a chicken-and-egg 500. This
helper degrades those drivers for the current process only; nothing is written to `.env`.

## Usage

```php
use Simtabi\Laranail\DbTools\Support\BootWithoutDatabase;

// In a service provider boot(), while the app is not yet installed:
$changed = BootWithoutDatabase::degradeToFilesystem();
// e.g. ['session.driver' => 'file', 'cache.default' => 'file']
```

Each entry only fires when the live value matches the "from" driver, so a deployment already on
`redis`/`file` is left untouched. Pair it with `DbTools::suspend()` to also stop availability
probing until the install completes.

## Configuration

The swap map is `config('db-tools.boot_without_database.drivers')`, shaped `{config key: {from => to}}`:

```php
'boot_without_database' => [
    'drivers' => [
        'session.driver' => ['database' => 'file'],
        'cache.default' => ['database' => 'file'],
        // 'queue.default' => ['database' => 'sync'],
    ],
],
```

Pass a custom map to `degradeToFilesystem($map)` to override per call. The method returns the keys
it actually changed.

---

[← Docs index](../../README.md#documentation)
