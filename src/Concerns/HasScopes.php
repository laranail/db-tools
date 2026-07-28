<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reusable Eloquent query scopes.
 */
trait HasScopes
{
    /**
     * Constrain a relation and eager-load it with the same constraint.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithWhereHas(Builder $query, string $relation, callable $constraint): Builder
    {
        return $query->whereHas($relation, $constraint)->with([$relation => $constraint]);
    }

    /**
     * Search across the model's searchable columns.
     *
     * On MySQL/MariaDB this uses a native FULLTEXT match in BOOLEAN MODE; on
     * every other driver it degrades to chained LIKE filters so the scope
     * stays portable. Columns come from the `$searchable` argument, falling
     * back to a `$searchable` property on the model.
     *
     * @param  Builder<static>  $query
     * @param  array<int, string>  $searchable
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, string $term, array $searchable = []): Builder
    {
        $columns = $this->resolveSearchColumns($searchable);

        if ($columns === [] || trim($term) === '') {
            return $query;
        }

        $grammar = $query->getConnection()->getQueryGrammar();
        $driver = $query->getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // Identifiers cannot be bound, so they are wrapped by the grammar —
            // and resolveSearchColumns() has already restricted them to names the
            // model declares, so nothing caller-supplied reaches the SQL.
            $wrapped = array_map(
                static fn (string $column): string => $grammar->wrap($column),
                $columns
            );

            return $query->whereRaw(
                'MATCH ('.implode(',', $wrapped).') AGAINST (? IN BOOLEAN MODE)',
                [$this->buildFulltextWildcards($term)]
            );
        }

        $pattern = '%'.$this->escapeLikeWildcards($term).'%';

        return $query->where(function (Builder $builder) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $builder->orWhere($column, 'LIKE', $pattern);
            }
        });
    }

    /**
     * Columns considered when searching; override via a `$searchable` property.
     *
     * @return array<int, string>
     */
    protected function searchableColumns(): array
    {
        return property_exists($this, 'searchable') ? $this->searchable : [];
    }

    /**
     * Restrict the requested columns to the ones the model declares searchable.
     *
     * `$searchable` is a public scope parameter, so `search($q, request('cols'))`
     * is an inviting call shape — and column identifiers cannot be bound. Rather
     * than try to sanitise arbitrary input into an identifier, only names the
     * model itself declares are allowed through.
     *
     * @param  array<int, string>  $requested
     * @return array<int, string>
     *
     * @throws InvalidArgumentException when a requested column is not declared
     */
    private function resolveSearchColumns(array $requested): array
    {
        $declared = $this->searchableColumns();

        if ($requested === []) {
            return $declared;
        }

        $unknown = array_diff($requested, $declared);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Column(s) [%s] are not searchable on %s. Declare them in its $searchable property.',
                implode(', ', $unknown),
                static::class,
            ));
        }

        return array_values($requested);
    }

    /**
     * Escape the LIKE metacharacters so a term is matched literally.
     *
     * Without this, searching for `%` matched every row.
     */
    private function escapeLikeWildcards(string $term): string
    {
        return addcslashes($term, '%_\\');
    }

    /**
     * Turn a search term into a MySQL BOOLEAN-MODE wildcard expression.
     */
    private function buildFulltextWildcards(string $term): string
    {
        $term = Str::replace(['-', '+', '<', '>', '@', '(', ')', '~'], '', $term);

        $words = Str::of($term)->explode(' ')
            ->filter(fn (string $word): bool => $word !== '')
            ->map(fn (string $word): string => '+'.$word.'*');

        return Arr::join($words->all(), ' ');
    }
}
