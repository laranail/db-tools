# Audit observer

`Simtabi\Laranail\DbTools\Observers\AuditObserver` stamps the
authenticated user's identifier into the `created_by` / `updated_by` /
`deleted_by` columns. It pairs naturally with the
[`auditColumns()`](macros.md#auditcolumns) schema macro.

## Usage

The canonical, native way to attach an observer on Laravel is the
`#[ObservedBy]` attribute — prefer it when you control the class declaration:

```php
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Observers\AuditObserver;

#[ObservedBy(AuditObserver::class)]
class Order extends Model {}
```

### `HasAuditObserver` trait

For cases where the attribute is awkward — wiring the observer conditionally, or
attaching it to a model you do not declare — use the
`Simtabi\Laranail\DbTools\Concerns\HasAuditObserver` trait:

```php
use Simtabi\Laranail\DbTools\Concerns\HasAuditObserver;

class Order extends Model
{
    use HasAuditObserver;
}
```

The trait defers the `observe()` call via `static::whenBooted(...)` rather than
calling it during `boot()` — calling `observe()` mid-boot would re-enter
Eloquent's boot guard. This mirrors how Laravel registers `#[ObservedBy]`
observers.

You can also attach it manually in `booted()` or a service provider's `boot()`:

```php
Order::observe(AuditObserver::class);
```

## What it stamps

- **creating** — sets `created_by` and `updated_by` (only when empty and the
  column applies to the model).
- **updating** — refreshes `updated_by`.
- **deleting** — for soft-deleting models that are *not* force-deleting, sets
  `deleted_by` and persists *only that column* with a targeted, keyed quiet
  update just before the soft delete (rather than `saveQuietly()`, which would
  flush every dirty attribute).

When there is no authenticated actor (guest / console / queue) the observer
leaves the nullable audit columns untouched rather than stamping `null`. A
column is considered applicable when it is in the model's `$fillable`, already
present in its attributes, or otherwise fillable (`modelHasColumn()`). The
column names are read from
[`config('db-tools.audit.*')`](../configuration.md#audit).

## Customizing the identifier

By default the stamp is `Auth::user()?->getAuthIdentifier()`. Override
`userIdentifier()` if your foreign key is not the authenticated user's primary
key — for example a UUID column:

```php
class AuditObserver extends \Simtabi\Laranail\DbTools\Observers\AuditObserver
{
    protected function userIdentifier(\Illuminate\Database\Eloquent\Model $model): mixed
    {
        return \Illuminate\Support\Facades\Auth::user()?->uuid;
    }
}
```

## Reference

```php
public function creating(Model $model): void;
public function updating(Model $model): void;
public function deleting(Model $model): void;
protected function userIdentifier(Model $model): mixed;     // override point
protected function modelHasColumn(Model $model, string $column): bool;
```

---
[← Docs index](../../README.md#documentation)
