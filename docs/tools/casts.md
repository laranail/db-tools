# Casts

Custom Eloquent attribute casts under
`Simtabi\Laranail\DbTools\Casts`.

## `CastMoney`

Stores money as integer **minor units** (e.g. cents) and reads it back as an
exact, currency-aware [`Brick\Money\Money`](https://github.com/brick/money)
value object. This avoids the rounding pitfalls of float money.

> **Note:** `CastMoney` previously returned a 2-decimal
> `float`. It now returns a `Brick\Money\Money` instance and serializes to an
> `{ amount, currency }` array. The package now requires `brick/money`.
> Migration: replace float arithmetic on the attribute with `Money` methods
> (e.g. `$order->amount->getAmount()` for the major-unit value, or compare with
> `->isEqualTo(...)`).

- `get`: `Money::ofMinor((int) $value, $currency)` — minor units → `Money`.
- `set`: a `Money` instance is stored as its integer minor amount; a numeric
  value (int/float/numeric-string) is treated as **major units** and converted
  to minor units (`HALF_UP` rounding). A non-numeric, non-`Money` value throws
  `InvalidArgumentException`; `null` passes through unchanged.

### Currency resolution

The currency comes from the cast argument first, falling back to
`config('db-tools.money.default_currency')` (which itself defaults to
`USD`). See [Configuration](../configuration.md#money).

```php
use Simtabi\Laranail\DbTools\Casts\CastMoney;

class Order extends Model
{
    protected $casts = [
        'amount' => CastMoney::class.':USD',  // explicit currency
        'fee'    => CastMoney::class,          // config default_currency
    ];
}

use Brick\Money\Money;

$order->amount = Money::of('19.99', 'USD');  // stored as 1999 (minor units)
$order->amount = 19.99;                       // major units -> stored as 1999
$order->amount;                               // Brick\Money\Money (USD 19.99)
$order->amount = 'abc';                       // throws InvalidArgumentException
```

### Serialization

`toArray()` / JSON output emit a plain `{ amount, currency }` array, where
`amount` is the major-unit string and `currency` is the ISO 4217 code:

```json
{ "amount": "19.99", "currency": "USD" }
```

## `CastDatetime`

Timezone-aware datetime cast. Values are **stored in UTC** and **presented in
the display timezone**: the cast argument if given, else `app.timezone`, else
`UTC`. Returns a `CarbonInterface` on read; `null` passes through.

```php
use Simtabi\Laranail\DbTools\Casts\CastDatetime;

class Event extends Model
{
    protected $casts = [
        // present this column in Europe/Paris, store as UTC:
        'published_at' => CastDatetime::class.':Europe/Paris',

        // no argument -> uses config('app.timezone'), falling back to UTC:
        'starts_at'    => CastDatetime::class,
    ];
}
```

- `get`: `Carbon::parse($value, 'UTC')->setTimezone($displayTimezone)`
- `set`: a `CarbonInterface` is converted to UTC as-is; a string is parsed in
  the display timezone first, then converted to a UTC datetime string.

---
[← Docs index](../../README.md#documentation)
