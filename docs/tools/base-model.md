# BaseModel

`Simtabi\Laranail\DbTools\Models\BaseModel` is an **optional** abstract
base Eloquent model bundling common conveniences: UUID-or-integer keys,
timestamp casts, lifecycle hook stubs, time scopes, and metadata helpers. Extend
it only if you want these defaults — nothing else in the package requires it.

```php
use Simtabi\Laranail\DbTools\Models\BaseModel;

class Order extends BaseModel
{
    protected $guarded = [];
}
```

> Soft deletes are intentionally **not** forced. Add Laravel's `SoftDeletes`
> trait (and a `deleted_at` column) on the concrete model when you need them.

## What it provides

- **Keys** — uses [`HasUuidsOrIntegerIds`](traits.md#hasuuidsorintegerids), so
  the primary key type follows your `db-tools.*` config (BIGINT / UUID /
  ULID).
- **Timestamps** — `$timestamps = true`, with `created_at` / `updated_at` cast to
  `datetime` (and the `CREATED_AT` / `UPDATED_AT` constants set explicitly).
- **Lifecycle hook stubs** — `boot()` wires the model's creating/created/
  updating/updated/deleting/deleted events to overridable no-op methods, so you
  can keep lifecycle logic on the model:

  ```php
  protected function handleCreating(): void { /* ... */ }
  protected function handleCreated(): void { /* ... */ }
  protected function handleUpdating(): void { /* ... */ }
  protected function handleUpdated(): void { /* ... */ }
  protected function handleDeleting(): void { /* ... */ }
  protected function handleDeleted(): void { /* ... */ }
  ```

## Time scopes

All scopes operate on the column returned by `timeScopeColumn()` (defaults to
`created_at`; override to scope on a different column).

```php
Order::today()->get();
Order::thisWeek()->get();
Order::thisMonth()->get();
Order::thisYear()->get();
Order::recent()->get();        // last 7 days
Order::recent(30)->get();      // last 30 days
```

## Metadata & helpers

```php
$order->getTableName();        // table name
$order->getModelName();        // short class name (class_basename)
$order->getFullModelName();    // fully-qualified class name
$order->isNew();               // ! exists
$order->isModified();          // isDirty()
$order->getCreatedAtForHumans(); // 'created_at' diffForHumans(), or null
$order->getUpdatedAtForHumans();
$order->toArrayWithMetadata(); // toArray() + a '_metadata' block
$order->reload();              // re-read attributes from the database
```

`toArrayWithMetadata()` appends a `_metadata` key containing `model_name`,
`table_name`, `is_new`, `is_modified`, `created_at_human`, and
`updated_at_human`.

## `reload()`

Re-reads the row **without global scopes** and re-syncs the original
attributes, then returns the model. It throws `ModelNotFoundException` when the
row no longer exists.

```php
$order->reload();          // fresh attributes, isModified() === false
```

> Two changes in 0.6.0. It used to read through `static::query()`, so a row
> that had stopped matching a global scope was not found and the method kept
> the stale in-memory values while still reporting success. It also never called
> `syncOriginal()`, so every reloaded attribute counted as an unsaved change and
> `isModified()` reported dirt on a model just read from the database.

---
[← Docs index](../../README.md#documentation)
