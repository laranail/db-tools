# Portable SQL

Four helpers cover queries and bulk writes that otherwise need a separate
branch per driver: `PortableQuery`, `ChunkedWriter`, `TableStatistics` and
`TriggerSuspender`. Each one gives the same result on SQLite, MySQL, MariaDB and
PostgreSQL.

## Literal LIKE matching: `PortableQuery`

`Simtabi\Laranail\DbTools\Query\PortableQuery` registers four query-builder
macros. They are available on Eloquent builders too:

| Macro | Matches |
|---|---|
| `whereLiteralLike($column, $value)` / `orWhereLiteralLike(…)` | `$column` contains `$value`, with `%`, `_` and `!` taken literally |
| `whereJsonArrayLiteralLike($column, $value)` / `orWhereJsonArrayLiteralLike(…)` | an element of the JSON array in `$column` contains `$value` |

```php
Icon::query()->where(fn ($q) => $q
    ->whereLiteralLike('name', $term)
    ->orWhereJsonArrayLiteralLike('keywords', $term)
    ->orWhereJsonArrayLiteralLike('tags', $term));
```

The macros avoid two traps:

- **The escape character is `!`.** A backslash cannot be used. In a MySQL
  string literal a backslash is itself an escape character, so `ESCAPE '\'`
  leaves the string unterminated and the query fails with error 1064. SQLite
  has no default escape character, so it must be declared.
  `PortableQuery::escapeLike()` and `containsPattern()` are public for queries
  you build yourself.
- **JSON columns are `json`, not `jsonb`.** `$table->json()` creates a `json`
  column on PostgreSQL, and PostgreSQL has no implicit cast from `json` to
  `jsonb`, so `jsonb_array_elements_text(col)` resolves to no function at all.
  On PostgreSQL the macro matches array elements with
  `json_array_elements_text(col::json)`. Other drivers match against the
  encoded JSON instead. That is looser, but it does not need a query per
  element.

## Chunked bulk writes: `ChunkedWriter`

```php
use Simtabi\Laranail\DbTools\Query\ChunkedWriter;

ChunkedWriter::for('icon_termables')->chunk(500)->insertOrIgnore($rows);
ChunkedWriter::for('icons')->upsert($rows, uniqueBy: ['path'], update: ['svg', 'hash']);
```

A multi-row statement binds `rows × columns` parameters. PostgreSQL and MySQL
cap that at 65,535 placeholders; SQLite caps it at 32,766 by default. Chunking
keeps every statement under the cap.

Both methods delegate to Laravel's `insertOrIgnore()` and `upsert()`, which
already produce the right dialect for each driver: `ON CONFLICT DO NOTHING` on
PostgreSQL and SQLite, `INSERT IGNORE` on MySQL and MariaDB. You do not need a
driver branch of your own.

## Table sizes and planner statistics: `TableStatistics`

```php
DbTools::tableSizes(['icons', 'icon_terms']);   // ['icons' => 5242880, 'icon_terms' => 98304]
DbTools::analyzeTables(['icons']);              // after a bulk load
```

| Driver | Size comes from | Analyze runs |
|---|---|---|
| pgsql | `pg_total_relation_size()` | `ANALYZE t` |
| mysql / mariadb | `information_schema.TABLES` | `ANALYZE TABLE t` |
| sqlite | the `dbstat` virtual table, if SQLite was compiled with it | `ANALYZE t` |

A size is `null` when the table does not exist or the driver cannot report
one. `tableSizes()` never throws.

## Suspending triggers during a bulk load: `TriggerSuspender`

```php
DbTools::withoutTriggers('icons', ['trg_icons_search_text'], function (bool $suspended) use ($rows): void {
    ChunkedWriter::for('icons')->upsert($rows, ['path'], ['svg']);

    if ($suspended) {
        // The trigger did not run, so rebuild the derived column for these rows now.
    }
});
```

Only PostgreSQL can suspend a trigger, and only the table's owner may do it.
The callback therefore receives whether suspension actually happened:

- On any other driver, or when disabling a trigger fails, the callback runs
  with `false` and the triggers stay live.
- Every trigger that was disabled is re-enabled in `finally`, even when the
  callback throws.

---

[← Docs index](../../README.md#documentation)
