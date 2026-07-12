# Soft-delete restore history

The [`softDeletesWithUndo()`](macros.md#softdeleteswithundo) macro adds a
`restored_at` column alongside Laravel's `deleted_at`. The
`HasSoftDeletesWithUndo` trait is the runtime companion: it records every
genuine soft-delete and every restore into a history table and stamps
`restored_at` on restore.

## Setup

Three pieces fit together:

1. The model columns — `softDeletesWithUndo()` on the model's own table.
2. The history table — publish and run its migration.
3. The trait — on the model, alongside Laravel's `SoftDeletes`.

### Columns + history table

```php
// The model's table:
Schema::create('orders', function (Blueprint $t): void {
    $t->id();
    $t->string('name');
    $t->softDeletesWithUndo();   // deleted_at + restored_at
    $t->timestamps();
});
```

Publish and run the history-table migration (see
[Configuration](../configuration.md#publishing-the-soft-delete-history-migration)):

```bash
php artisan vendor:publish --tag=db-tools-migrations
php artisan migrate
```

Or build the columns yourself with the
[`softDeleteHistory()`](macros.md#softdeletehistory) macro.

### The trait

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Simtabi\Laranail\DbTools\Concerns\HasSoftDeletesWithUndo;

class Order extends Model
{
    use SoftDeletes;              // required
    use HasSoftDeletesWithUndo;
}
```

`HasSoftDeletesWithUndo` requires Laravel's `SoftDeletes` and the
`softDeletesWithUndo()` columns; without them it has nothing to track.

## What it records

The trait hooks the model's `deleted` and `restored` events:

- **On a genuine soft-delete** — writes a `deleted` history row. A force-delete
  (or a non-soft-deletable model) is skipped, since the row vanishes and a
  history entry would dangle.
- **On restore** — stamps the model's `restored_at` (written quietly, so no
  further events fire) and writes a `restored` history row.

Each history row carries the polymorphic `record_type` / `record_id`, the
`action` (`deleted` | `restored`), a nullable `actor_id` (the authenticated
user, resolved from `Auth::id()` — null for guest/console/queue contexts), a
nullable `reason`, and a `happened_at` timestamp. History writes are best-effort
and wrapped in a transaction; a logging failure never breaks the
delete/restore the caller asked for.

## Helpers

```php
// Restore and ensure a history row is written. Equivalent to a native
// restore() (the restored event does the work) but an explicit entry point.
$order->restoreWithHistory();

// Query this record's history, newest first. Returns a base query builder
// over the history table, already scoped to this record.
$rows = $order->softDeleteHistory()->get();
```

The history table has no dedicated model — `softDeleteHistory()` returns an
`Illuminate\Database\Query\Builder` scoped to the record's morph class and key,
ordered by `happened_at` descending.

## Reference

```php
public static function bootHasSoftDeletesWithUndo(): void;
public function restoreWithHistory(): bool;
public function softDeleteHistory(): \Illuminate\Database\Query\Builder;
protected function stampRestoredAt(): void;
protected function recordSoftDeleteHistory(string $action, ?string $reason = null): void;
protected function softDeleteHistoryActor(): int|string|null;  // override point
protected function softDeleteHistoryTable(): string;
```

The table name comes from `config('db-tools.soft_delete_history.table')`
(default `soft_delete_history`) — see [Configuration](../configuration.md#soft_delete_history).

---
[← Docs index](../../README.md#documentation)
