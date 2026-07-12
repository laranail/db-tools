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

---
[← Docs index](../../README.md#documentation)
