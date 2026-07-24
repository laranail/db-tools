<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Casts;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Cast an integer "minor unit" column (e.g. cents) to a brick/money
 * {@see Money} value object and back. Values are stored as whole minor units
 * and read as exact, currency-aware Money instances.
 *
 * The currency is taken from the cast argument (e.g. CastMoney::class.':EUR')
 * or, when absent, the "money.default_currency" config value (USD fallback).
 *
 * @implements CastsAttributes<Money|null, int|null>
 */
class CastMoney implements CastsAttributes, SerializesCastableAttributes
{
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

        return Money::ofMinor((int) $value, $this->currency());
    }

    /**
     * Store a Money instance or a major-unit numeric value as minor units.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Money) {
            return $value->getMinorAmount()->toInt();
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return Money::of((string) $value, $this->currency(), roundingMode: RoundingMode::HalfUp)
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
            $value = Money::ofMinor((int) $value, $this->currency());
        }

        return [
            'amount' => (string) $value->getAmount(),
            'currency' => $value->getCurrency()->getCurrencyCode(),
        ];
    }

    /**
     * Resolve the ISO 4217 currency code for this cast.
     */
    private function currency(): string
    {
        $currency = $this->currency ?? config('laranail.db-tools.money.default_currency', 'USD');

        return is_string($currency) && $currency !== '' ? $currency : 'USD';
    }
}
