# Eager-load helpers

`Simtabi\Laranail\DbTools\Concerns\LoadsAggregatesIfMissing` is an opt-in
trait that adds "skip work already done" conveniences over Eloquent's native
lazy-eager-loading helpers. It is pure delegation — nothing here changes how
loading works, it only short-circuits when the resulting attribute is already
present.

Eloquent already ships `loadMissing()` (loads relations only when absent), but
the aggregate loaders `loadCount()` and `loadAggregate()` always re-run their
queries even when the `*_count` / aggregate attribute is already set. This trait
closes that gap.

## Usage

```php
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Concerns\LoadsAggregatesIfMissing;

class Order extends Model
{
    use LoadsAggregatesIfMissing;
}
```

Opt-in per model; it is not added to `BaseModel`.

```php
// Thin alias for native loadMissing() — provided for naming symmetry.
$order->loadIfMissing('lines');

// Load lines_count only if that attribute is not already set.
$order->loadCountIfMissing('lines');

// Load a column aggregate only when its attribute is missing.
$order->loadAggregateIfMissing('lines', 'total', 'sum'); // lines_sum_total
```

Constrained relations work too — the `['relation' => closure]` form Eloquent's
own `loadCount()` / `loadAggregate()` accept:

```php
$order->loadCountIfMissing([
    'lines' => fn ($query) => $query->where('qty', '>', 0),
]);
```

> Before 0.6.0 that form raised a `TypeError`. The "already loaded?" filter ran
> over the array's values with a string-typed callback, so under
> `strict_types` a closure value fatalled instead of loading anything.

Each method returns `static`, so calls chain.

## How "missing" is decided

The trait mirrors Eloquent's `withAggregate` attribute naming: a plain count
becomes `{relation}_count`, a column aggregate becomes
`{relation}_{function}_{column}` (snake-cased). A relation is loaded only when
that attribute is not already present on the model.

## Reference

```php
public function loadIfMissing(array|string $relations): static;
public function loadCountIfMissing(array|string $relations): static;
public function loadAggregateIfMissing(array|string $relations, string $column, string $function = 'count'): static;
```

---
[← Docs index](../../README.md#documentation)
