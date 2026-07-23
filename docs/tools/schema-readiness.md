# Schema readiness

Report whether a database is reachable, migrated, and has the tables an app needs — without ever throwing — so an application can proceed and warn instead of failing blind.

## Why

An installed app whose database is reachable but not fully migrated (a restored dump, a half-run
migration) should not blank out with a 500. `SchemaReadiness` turns "is the schema usable?" into a
structured, never-throwing report you can act on: warn, gate a deploy, or degrade a feature.

## Usage

```php
use Simtabi\Laranail\DbTools\DbTools;
use Simtabi\Laranail\DbTools\Schema\SchemaStatus;

$report = DbTools::schemaReport(['migrations', 'users', 'settings']);

$report->status;              // SchemaStatus::Down | Empty | Pending | Ready
$report->isReady();           // bool
$report->reachable;           // bool
$report->hasMigrationsTable;  // bool
$report->missingTables;       // list<string>
$report->message();           // human, action-oriented string
$report->toArray();           // for logs / JSON

// Run only when the schema is ready:
DbTools::schemaReadiness()->whenReady(fn () => Setting::current(), default: null);
```

Required tables default to `config('db-tools.readiness.required_tables')` when you pass none.

## Statuses

| Status | Meaning |
|--------|---------|
| `down` | The connection is unreachable (or the guard is suspended). |
| `empty` | Reachable, but there is no `migrations` table — nothing migrated. |
| `pending` | Migrated, but one or more required tables are still missing. |
| `ready` | Reachable and every required table is present. |

Readiness is table-level: `ready` means the required tables exist, not that zero migration files
are pending (counting those couples to migration paths and is left to the application).

## Events

Any non-`ready` report fires `Simtabi\Laranail\DbTools\Events\SchemaNotReady`, carrying the report.
A default listener logs it (`config('db-tools.guard.log_events')`); toggle emission with
`config('db-tools.guard.emit_events')`.

## CLI

`php artisan laranail::db-tools.health` prints the report; `--strict` exits non-zero unless the
status is `ready`; `--tables=` and `--connection=` override the defaults. Handy in deploy gates.

## How it works

Composed on the [availability guard](availability-guard.md) (reachable, without hanging) and the
[table verifier](table-verification.md) (which tables exist). Reports are memoized per
(connection, required-set) for the instance lifetime, so repeated boot/middleware checks stay cheap.

---

[← Docs index](../../README.md#documentation)
