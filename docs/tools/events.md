# Events

`Simtabi\Laranail\DbTools\Events\DatabaseEvents` is a lightweight event
class for database configuration and migration lifecycle moments. It extends the
shared `BaseEvent` and is built through static factory methods.

> Seeding events live in the `laranail/package-tools` package.

## Factory methods

Each factory returns a populated `DatabaseEvents` instance you can dispatch with
Laravel's `event()` helper.

```php
use Simtabi\Laranail\DbTools\Events\DatabaseEvents;

event(DatabaseEvents::configuring($databaseConfig));
event(DatabaseEvents::configured($databaseConfig));
event(DatabaseEvents::connectionFailed('Connection refused', $databaseConfig));
event(DatabaseEvents::migrationStarted('2024_01_01_create_orders'));
event(DatabaseEvents::migrationCompleted('2024_01_01_create_orders'));
event(DatabaseEvents::migrationFailed('2024_01_01_create_orders', 'duplicate column'));
```

Every factory accepts trailing `?Request $request = null` and
`?array $metadata = null` arguments that are merged into the event metadata.

| Factory | Action | Extra metadata |
|---------|--------|----------------|
| `configuring(array $config, …)` | `configuring` | `database_config` |
| `configured(array $config, …)` | `configured` | `database_config` |
| `connectionFailed(string $reason, array $config = [], …)` | `connection_failed` | `reason`, `database_config` |
| `migrationStarted(string $name, …)` | `migration_started` | `migration_name` |
| `migrationCompleted(string $name, …)` | `migration_completed` | `migration_name` |
| `migrationFailed(string $name, string $reason, …)` | `migration_failed` | `migration_name`, `reason` |

## Accessors

```php
$event->getAction();          // e.g. 'migration_failed'
$event->getType();            // 'database'
$event->getMetadata();        // array<string, mixed>
$event->getDisplayName();     // 'Database Migration Failed'
$event->getDescription();     // human-readable, action-aware
$event->getPriorityLevel();   // 'low' | 'medium' | 'high'
$event->isSuccessful();       // true for configured / migration_completed
$event->getResult();          // 'success' | 'failure' | 'in_progress' | 'unknown'
$event->getDatabaseConfig();  // ?array
$event->getMigrationName();   // ?string
$event->getFailureReason();   // ?string
$event->firedAt;              // float microtime when constructed
```

Priority is derived from the action: failures are `high`, completions are
`medium`, and start/in-progress actions are `low`.

## `BaseEvent`

The abstract base (`Events\BaseEvent`) holds the shared fields — `firedAt`,
`action`, `type`, `request`, `metadata` — and default `getDisplayName()` /
`getDescription()` / `getPriorityLevel()` implementations that `DatabaseEvents`
overrides. Subclass it for your own event families: call `createEvent()` from a
static factory to populate the shared fields.

## Availability / readiness events

Four dispatchable events (Laravel's `Dispatchable`, fired via the event dispatcher, listenable the
usual way) report database health, all emitted best-effort — a missing dispatcher or a throwing
listener never breaks the check that raised them:

| Event | Fired when | Payload |
|-------|-----------|---------|
| `Events\DatabaseUnavailable` | The [availability guard](availability-guard.md) probes a connection and finds it unreachable (once per connection per request; never while suspended). | `?string $connection` |
| `Events\SchemaNotReady` | A [schema-readiness](schema-readiness.md) report comes back as anything but `ready`. | `SchemaReadinessReport $report` |
| `Events\DatabaseAvailable` | The guard finds a connection reachable: the first time in a process, and on every transition back from unreachable. | `?string $connection` |
| `Events\SchemaReady` | A schema-readiness report comes back `ready`. | `SchemaReadinessReport $report` |

Turn off emission of all four with `config('laranail.db-tools.guard.emit_events')`.

### Logging a change of state

A default listener (`Listeners\LogDatabaseIssues`) logs them. Opt out with
`config('laranail.db-tools.guard.log_events')` and listen yourself.

It logs when the state changes, not on every check. A health check probing a dead
database every 15 seconds would otherwise write two warnings per probe, about
11,500 lines a day, and bury the lines you need during the outage. Per connection:

| Moment | Level | Message |
|--------|-------|---------|
| Becomes unreachable | `warning` | `[db-tools] Database connection unavailable.` |
| Still unreachable, once per interval | `warning` | `[db-tools] Database connection still unavailable.` (with `since`, `duration_seconds`) |
| Reachable again | `info` | `[db-tools] Database connection available again.` |
| Schema becomes not ready, or changes status | `warning` | `[db-tools] Schema not ready: …` (the report) |
| Still not ready, once per interval | `warning` | `[db-tools] Schema still not ready: …` |
| Ready again | `info` | `[db-tools] Schema ready again.` |

```php
// config/laranail/db-tools.php
'guard' => [
    'log_reminder_interval'  => 300,     // seconds; 0 logs every check
    'log_cache_store'        => 'file',  // null = the default store
    'log_unreachable_schema' => false,   // the "unavailable" line already says it
],
```

The listener remembers what it logged in the cache (`Cache::add` with a TTL),
keyed by connection and state, so the throttle holds across requests and
workers. When the cache store throws, because it is the database that is down,
it falls back to an in-process guard and carries on. In PHP-FPM that fallback
lasts one request, so set `log_cache_store` to a store that does not use the
database if yours does. The listener never throws, not even when the log sink
fails as well.

An unreachable database also yields a `down` schema report, so by default it logs
two lines at onset. `log_unreachable_schema` set to `false` drops the second;
it defaults to `true` so the log does not change shape on upgrade.

---
[← Docs index](../../README.md#documentation)
