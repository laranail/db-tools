<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Casts;

use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Simtabi\Laranail\DbTools\Exceptions\MoneyCurrencyException;

/**
 * Cast an integer "minor unit" column (e.g. cents) to a brick/money
 * {@see Money} value object and back. Values are stored as whole minor units
 * and read as exact, currency-aware Money instances.
 *
 * ## Choosing the currency
 *
 * | Cast argument | Currency |
 * |---|---|
 * | `CastMoney::class.':EUR'` | fixed, EUR |
 * | `CastMoney::class.':@currency'` | per row, read from the `currency` column |
 * | `CastMoney::class` | `laranail.db-tools.money.default_currency`, else USD |
 *
 * A `@column` argument is for multi-currency tables, where the amount means
 * nothing without the row that carries it. It is strict on purpose: if the
 * column was not loaded, or holds nothing, the cast throws
 * {@see MoneyCurrencyException} rather than substituting a default. Quietly
 * falling back to USD would read a KES balance back as dollars and, on the next
 * save, write it back that way — silent, and unrecoverable once it has spread.
 *
 * The column may hold a string or a backed enum.
 *
 * ## A numeric assignment means major units
 *
 * `$model->price = 12.34` stores `1234`. Assigning a `Money` instance stores its
 * minor amount directly. Both round half-up; neither silently truncates.
 *
 * @implements CastsAttributes<Money|null, int|null>
 */
class CastMoney implements CastsAttributes, SerializesCastableAttributes
{
    /**
     * Marks a cast argument as a column reference rather than a currency code.
     */
    private const string COLUMN_PREFIX = '@';

    public function __construct(private readonly ?string $currency = null) {}

    /**
     * Read minor units from storage as a Money value object.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::ofMinor(
            $this->toMinorUnits($value, $key),
            $this->currencyFor($key, $attributes),
        );
    }

    /**
     * Store a Money instance, or a major-unit numeric value, as minor units.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Money) {
            $this->assertCurrencyMatchesRow($value, $key, $attributes);

            // A Money instance already carries its own scale, so the amount
            // needs no currency lookup — only a rounding mode, for the custom
            // contexts whose minor amount is not a whole number.
            return $this->toMinorUnits($value->getMinorAmount(), $key);
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return Money::of((string) $value, $this->currencyForWrite($key, $attributes), roundingMode: RoundingMode::HalfUp)
                ->getMinorAmount()
                ->toInt();
        }

        throw new InvalidArgumentException(
            "The [{$key}] money value must be a ".Money::class.' instance or a numeric value.'
        );
    }

    /**
     * Serialize the Money value to a plain array for toArray()/JSON output.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{amount: string, currency: string}|null
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof Money) {
            $value = Money::ofMinor(
                $this->toMinorUnits($value, $key),
                $this->currencyFor($key, $attributes),
            );
        }

        return [
            'amount' => (string) $value->getAmount(),
            'currency' => $value->getCurrency()->getCurrencyCode(),
        ];
    }

    /**
     * Coerce a stored value to whole minor units.
     *
     * Rounds rather than casts. `(int) '1050.7'` is 1050, which loses money
     * quietly and makes a write-then-read round trip non-idempotent against the
     * half-up rounding on the way in.
     */
    private function toMinorUnits(mixed $value, string $key): int
    {
        try {
            return BigDecimal::of($value)->toScale(0, RoundingMode::HalfUp)->toInt();
        } catch (MathException $e) {
            throw MoneyCurrencyException::invalidMinorAmount($key, $e->getMessage());
        }
    }

    /**
     * Resolve the ISO 4217 currency code for this cast.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function currencyFor(string $key, array $attributes): string
    {
        $column = $this->currencyColumn();

        if ($column !== null) {
            return $this->currencyFromColumn($column, $key, $attributes);
        }

        $currency = $this->currency ?? config('laranail.db-tools.money.default_currency', 'USD');

        return is_string($currency) && $currency !== '' ? $currency : 'USD';
    }

    /**
     * Resolve the currency for a write of a bare number.
     *
     * Identical to {@see currencyFor()} except for the diagnosis when a
     * per-row currency has not been assigned yet: on the way in that is an
     * ordering problem with two easy fixes, not an unloaded column.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function currencyForWrite(string $key, array $attributes): string
    {
        $column = $this->currencyColumn();

        if ($column !== null && ! array_key_exists($column, $attributes)) {
            throw MoneyCurrencyException::currencyNotYetSet($column, $key);
        }

        return $this->currencyFor($key, $attributes);
    }

    /**
     * The sibling column holding the currency, when one was configured.
     */
    private function currencyColumn(): ?string
    {
        if ($this->currency === null || ! str_starts_with($this->currency, self::COLUMN_PREFIX)) {
            return null;
        }

        return substr($this->currency, 1);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function currencyFromColumn(string $column, string $key, array $attributes): string
    {
        if (! array_key_exists($column, $attributes)) {
            throw MoneyCurrencyException::columnNotLoaded($column, $key);
        }

        $code = $this->normalizeCurrencyCode($attributes[$column]);

        if ($code === null) {
            throw MoneyCurrencyException::columnEmpty($column, $key);
        }

        return $code;
    }

    /**
     * Accept a plain code or a backed enum; anything else is not a currency.
     */
    private function normalizeCurrencyCode(mixed $raw): ?string
    {
        if ($raw instanceof BackedEnum) {
            $raw = $raw->value;
        }

        if (is_int($raw)) {
            $raw = (string) $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return trim($raw);
    }

    /**
     * Refuse a Money whose currency contradicts the row it is being stored in.
     *
     * Only checked when the row's currency is already resolvable. During a
     * single `create()` the currency column may not have been assigned yet —
     * attribute order is the caller's array order — so an unresolvable currency
     * is not treated as a mismatch.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertCurrencyMatchesRow(Money $value, string $key, array $attributes): void
    {
        $column = $this->currencyColumn();

        if ($column === null || ! array_key_exists($column, $attributes)) {
            return;
        }

        $rowCurrency = $this->normalizeCurrencyCode($attributes[$column]);

        if ($rowCurrency === null) {
            return;
        }

        $assigned = $value->getCurrency()->getCurrencyCode();

        if (strcasecmp($assigned, $rowCurrency) !== 0) {
            throw MoneyCurrencyException::mismatch($key, $assigned, $rowCurrency);
        }
    }
}
