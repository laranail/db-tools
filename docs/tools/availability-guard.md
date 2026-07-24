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

// Guarded table access:
DbTools::whenTable('global_settings', fn () => Setting::current()->value, default: 'fallback');

// Install / first-boot window: stop probing entirely until the schema exists.
DbTools::suspend();     // isAvailable()/hasTable() now return false without touching the DB
// ... run the installer / migrations ...
DbTools::resume();      // re-probe from here on
```

## API — `DatabaseAvailabilityInterface`

| Method | Returns | Description |
|--------|---------|-------------|
| `isAvailable(?string $connection = null)` | `bool` | Bounded PDO probe (memoized); false on any failure. |
| `hasTable(string $table, ?string $connection = null)` | `bool` | False when unreachable **or** the table is missing. |
| `whenAvailable(callable $cb, mixed $default = null, ?string $connection = null)` | `mixed` | Runs `$cb` if available, else returns `$default`. |
| `whenTable(string $table, callable $cb, mixed $default = null, ?string $connection = null)` | `mixed` | Runs `$cb` if the table exists, else `$default`. |
| `suspend()` | `static` | Assume unavailable and stop probing (install/first-boot). Preserves any custom prober. |
| `resume()` | `static` | Lift a suspension and flush the memo so the next check probes for real. |
| `isSuspended()` | `bool` | Whether probing is currently suspended. |
| `flush(?string $connection = null)` | `void` | Forget the memoized probe for one/all connections. |

Concrete extras on `DatabaseGuard`: `probeUsing(?callable $prober)` (runtime-swappable strategy —
e.g. a TCP ping or cached flag; `null` restores the default probe), `Macroable`, and the static
`reachable()` / `tableExists()` / `resolve()` self-bootstrapping entry points.

## Fast-fail probe

The built-in availability probe is bounded by a short connect timeout so an unreachable or
blackholed host fails in ~2 s instead of blocking for the driver default (~30 s), and it **opens the
real connection once and reuses it** — never a throwaway or a clone:

- Before the connection is first resolved, the probe adds a **connect-only** timeout to its config
  (`PDO::ATTR_TIMEOUT` for mysql/mariadb/sqlsrv — verified not to cap query time; `connect_timeout`
  for pgsql; SQLite is local, so no timeout). The connection is then built with that timeout and
  every later query in the request reuses it, so a healthy boot-time check costs **one** connection,
  not two.
- It never purges the connection (that would close a live PDO and wipe a `:memory:` SQLite
  database), and it leaves an already-resolved connection untouched — just reusing it.

The connect timeout it adds is connect-only, so it bounds a dead host without capping query time;
it persists on the connection for the rest of the process (a benign, generally desirable default).
Tune with `config('laranail.db-tools.guard.probe_timeout')` (seconds, default `2`).

## Events

When a probe finds a connection unreachable, the guard fires
`Simtabi\Laranail\DbTools\Events\DatabaseUnavailable` (once per connection per request, never while
suspended). A default listener logs it; opt out with `config('laranail.db-tools.guard.log_events')` and
listen yourself. Disable emission entirely with `config('laranail.db-tools.guard.emit_events')`.

## How it works

The guard layers on the existing `DatabaseConnectionTester` (bounded probe → `isAvailable`) and
`DatabaseSchemaInspector` (`hasTable`), memoizing each connection's availability. It is bound as a
singleton (`DatabaseAvailabilityInterface`) and honours `config('laranail.db-tools.guard.memoize')`.

---

[← Docs index](../../README.md#documentation)
