<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Exceptions;

use Simtabi\Laranail\DbTools\Casts\CastMoney;

/**
 * Thrown when {@see CastMoney} is configured to read its currency from a
 * sibling column and that column cannot supply one.
 *
 * Substituting a default currency here would be silent data corruption: the
 * amount would be read back, and eventually written back, denominated in a
 * currency nobody chose.
 */
class MoneyCurrencyException extends DbToolsException
{
    /**
     * The currency column was not among the loaded attributes.
     *
     * Usually a partial `select()` that omitted it, which means the currency is
     * genuinely unknowable rather than merely absent.
     */
    public static function columnNotLoaded(string $column, string $key): self
    {
        return new self(
            message: "The [{$key}] money cast reads its currency from the [{$column}] column, "
                .'which was not loaded. Select it, or give the cast a fixed currency.',
            code: 2101,
            context: ['column' => $column, 'attribute' => $key],
        );
    }

    /**
     * The column was loaded but held nothing usable.
     */
    public static function columnEmpty(string $column, string $key): self
    {
        return new self(
            message: "The [{$key}] money cast reads its currency from the [{$column}] column, "
                .'which is empty. Every money row needs a currency.',
            code: 2102,
            context: ['column' => $column, 'attribute' => $key],
        );
    }

    /**
     * A Money instance was assigned whose currency contradicts the row's.
     *
     * Storing it would denominate the minor units in one currency while every
     * later read interpreted them as another.
     */
    public static function mismatch(string $key, string $assigned, string $row): self
    {
        return new self(
            message: "The [{$key}] money value is in {$assigned} but the row's currency is {$row}. "
                .'Convert the amount first, or set the currency column in the same operation.',
            code: 2103,
            context: ['attribute' => $key, 'assigned' => $assigned, 'row' => $row],
        );
    }

    /**
     * A bare number was assigned before the row's currency was known.
     *
     * Converting major units to minor needs the currency's scale — 2 for USD,
     * 0 for JPY, 3 for KWD — so there is nothing safe to assume here.
     */
    public static function currencyNotYetSet(string $column, string $key): self
    {
        return new self(
            message: "The [{$key}] money cast needs the [{$column}] column to convert a bare number, "
                ."and it has not been set yet. Assign [{$column}] first, or assign a Money instance, "
                .'which carries its own currency and needs no lookup.',
            code: 2105,
            context: ['column' => $column, 'attribute' => $key],
        );
    }

    /**
     * The value in storage is not a usable minor-unit amount.
     */
    public static function invalidMinorAmount(string $key, string $reason): self
    {
        return new self(
            message: "The [{$key}] money value is not a valid minor-unit amount: {$reason}",
            code: 2104,
            context: ['attribute' => $key],
        );
    }
}
