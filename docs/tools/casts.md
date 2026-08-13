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

- `get`: minor units → `Money`. A fractional stored value (a `DECIMAL` column,
  or a driver handing back strings) is **rounded**, not truncated — `(int)
  '1050.7'` is `1050`, which loses money quietly and makes a round trip
  non-idempotent against the half-up rounding on the way in.
- `set`: a `Money` instance is stored as its integer minor amount; a numeric
  value (int/float/numeric-string) is treated as **major units** and converted.
  Both paths round `HALF_UP`. A non-numeric, non-`Money` value throws
  `InvalidArgumentException`; `null` passes through unchanged.

### Currency resolution

| Cast argument | Currency |
|---|---|
| `CastMoney::class.':EUR'` | fixed — EUR |
| `CastMoney::class.':@currency'` | per row — read from the `currency` column |
| `CastMoney::class` | `laranail.db-tools.money.default_currency`, else `USD` |

See [Configuration](../configuration.md#money).

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

### Per-row currency

For a multi-currency table, where an amount means nothing without the row that
carries it, point the cast at a sibling column with `@`:

```php
class Wallet extends Model
{
    protected $casts = ['balance' => CastMoney::class.':@currency'];
}
```

The column may hold a plain code or a backed enum. This mode is **strict**: if
the column was not loaded, or holds nothing, the cast throws
`Exceptions\MoneyCurrencyException` rather than substituting a default. Quietly
falling back to `USD` would read a KES balance back as dollars and, on the next
save, write it back that way — silent, and unrecoverable once it has spread.

| Situation | Code | Behaviour |
|---|---|---|
| Column absent from the loaded attributes (a partial `select()`) | 2101 | throws |
| Column loaded but `null` or blank | 2102 | throws |
| A `Money` assigned whose currency contradicts the row's | 2103 | throws |
| Stored value is not a usable minor amount | 2104 | throws |
| A bare number assigned before the currency column is set | 2105 | throws |

The last one is an ordering constraint, and it has two fixes. Converting major
units to minor needs the currency's scale — 2 for USD, 0 for JPY, 3 for KWD —
so there is nothing safe to assume:

```php
// Throws (2105): balance is assigned before currency is known.
Wallet::create(['balance' => 100.00, 'currency' => 'KES']);

// Fine — the currency is set first.
Wallet::create(['currency' => 'KES', 'balance' => 100.00]);

// Fine — Money carries its own currency, so order does not matter.
Wallet::create(['balance' => Money::of('100.00', 'KES'), 'currency' => 'KES']);

// Fine — a loaded row already has its currency in the attribute array.
$wallet->balance = 250.50;
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
