<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Query;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * LIKE matching that behaves the same on SQLite, MySQL, MariaDB and PostgreSQL.
 *
 * Two portability traps are handled here so a model scope does not have to:
 *
 * - **The escape character.** User input has to match literally, so `%` and `_`
 *   need escaping. A backslash cannot be the escape character: inside a MySQL
 *   string literal it is itself an escape, so `ESCAPE '\'` is an unterminated
 *   string and every query fails with 1064. SQLite has no default escape
 *   character at all. `!` works on every driver.
 * - **JSON array elements.** `$table->json()` emits `json`, not `jsonb`, on
 *   PostgreSQL, and there is no implicit cast between the two, so a
 *   `jsonb_array_elements_text()` over such a column resolves to no function.
 *   PostgreSQL matches array elements with `json_array_elements_text(col::json)`;
 *   the other drivers fall back to a LIKE over the encoded JSON, which is looser
 *   but needs no query per element.
 *
 * Registered as query-builder macros, so Eloquent builders reach them too:
 *
 *     Icon::query()->where(fn ($q) => $q
 *         ->whereLiteralLike('name', $term)
 *         ->orWhereJsonArrayLiteralLike('keywords', $term));
 */
final class PortableQuery
{
    public const string ESCAPE = '!';

    /**
     * Escape `$value` so LIKE matches it literally, using {@see ESCAPE}.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE . self::ESCAPE, self::ESCAPE . '%', self::ESCAPE . '_'],
            $value,
        );
    }

    /**
     * A `%…%` pattern matching `$value` anywhere, literally.
     */
    public static function containsPattern(string $value): string
    {
        return '%' . self::escapeLike($value) . '%';
    }

    /**
     * `$column LIKE '%value%'`, with `$value` matched literally.
     */
    public static function whereLiteralLike(Builder $query, string $column, string $value, string $boolean = 'and'): Builder
    {
        return $query->whereRaw(
            new Expression($query->getGrammar()->wrap($column) . ' LIKE ? ' . self::escapeClause()),
            [self::containsPattern($value)],
            $boolean,
        );
    }

    /**
     * Any element of the JSON array in `$column` contains `$value`, literally.
     * Element-exact on PostgreSQL; a LIKE over the encoded JSON elsewhere.
     */
    public static function whereJsonArrayLiteralLike(Builder $query, string $column, string $value, string $boolean = 'and'): Builder
    {
        $wrapped = $query->getGrammar()->wrap($column);

        $sql = $query->getGrammar() instanceof PostgresGrammar
            ? "EXISTS (SELECT 1 FROM json_array_elements_text({$wrapped}::json) AS laranail_elem WHERE laranail_elem LIKE ? " . self::escapeClause() . ')'
            : "{$wrapped} LIKE ? " . self::escapeClause();

        return $query->whereRaw(new Expression($sql), [self::containsPattern($value)], $boolean);
    }

    public static function register(): void
    {
        if (! Builder::hasMacro('whereLiteralLike')) {
            Builder::macro('whereLiteralLike', function (string $column, string $value, string $boolean = 'and'): Builder {
                /** @var Builder $this */
                return PortableQuery::whereLiteralLike($this, $column, $value, $boolean);
            });
        }

        if (! Builder::hasMacro('orWhereLiteralLike')) {
            Builder::macro('orWhereLiteralLike', function (string $column, string $value): Builder {
                /** @var Builder $this */
                return PortableQuery::whereLiteralLike($this, $column, $value, 'or');
            });
        }

        if (! Builder::hasMacro('whereJsonArrayLiteralLike')) {
            Builder::macro('whereJsonArrayLiteralLike', function (string $column, string $value, string $boolean = 'and'): Builder {
                /** @var Builder $this */
                return PortableQuery::whereJsonArrayLiteralLike($this, $column, $value, $boolean);
            });
        }

        if (! Builder::hasMacro('orWhereJsonArrayLiteralLike')) {
            Builder::macro('orWhereJsonArrayLiteralLike', function (string $column, string $value): Builder {
                /** @var Builder $this */
                return PortableQuery::whereJsonArrayLiteralLike($this, $column, $value, 'or');
            });
        }
    }

    private static function escapeClause(): string
    {
        return "ESCAPE '" . self::ESCAPE . "'";
    }
}
