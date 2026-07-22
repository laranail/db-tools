# Availability guard

A boot-safe database-availability guard: probe whether a connection is reachable and run conditional / boot-time logic that degrades gracefully when the database is down or not yet migrated — instead of throwing a `QueryException`.

## Why

`Schema::hasTable('foo')` opens a connection to answer, so it throws when the database is
unreachable (a machine with no DB, a fresh clone, a CI/container build running `composer install`
→ `package:discover`). `DatabaseGuard` turns that into a boolean.

## Usage

```php
use Simtabi\Laranail\DbTools\DbTools;
use Simtabi\Laranail\DbTools\Guard\DatabaseGuard;
use Simtabi\Laranail\DbTools\Guard\Contracts\DatabaseAvailabilityInterface;

// Facade-style entry points (provider registered):
DbTools::isAvailable();                       // bool, never throws
DbTools::tableExists('global_settings');      // false if unreachable OR missing
DbTools::whenAvailable(fn () => Setting::current()->value, default: 'fallback');

// Dependency injection:
app(DatabaseAvailabilityInterface::class)->isAvailable('reporting');

// Earliest-boot (before this package's provider is registered) — static, self-bootstrapping:
if (! DatabaseGuard::tableExists('global_settings')) {
    return; // safe in a service provider boot() during package:discover with no DB
}
```

## API — `DatabaseAvailabilityInterface`

| Method | Returns | Description |
|--------|---------|-------------|
| `isAvailable(?string $connection = null)` | `bool` | PDO probe (memoized); false on any failure. |
| `hasTable(string $table, ?string $connection = null)` | `bool` | False when unreachable **or** the table is missing. |
| `whenAvailable(callable $cb, mixed $default = null, ?string $connection = null)` | `mixed` | Runs `$cb` if available, else returns `$default`. |
| `flush(?string $connection = null)` | `void` | Forget the memoized probe for one/all connections. |

Concrete extras on `DatabaseGuard`: `probeUsing(?callable $prober)` (runtime-swappable strategy —
e.g. a TCP ping or cached flag; `null` restores the default probe), `Macroable`, and the static
`reachable()` / `tableExists()` / `resolve()` self-bootstrapping entry points.

## How it works

The guard layers on the existing `DatabaseConnectionTester` (getPdo probe → `isAvailable`) and
`DatabaseSchemaInspector` (`hasTable`), memoizing each connection's availability. It is bound as a
singleton (`DatabaseAvailabilityInterface`) and honours `config('db-tools.guard.memoize')`.

---

[← Docs index](../../README.md#documentation)
