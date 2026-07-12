<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

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
        $columns = $searchable !== [] ? $searchable : $this->searchableColumns();

        if ($columns === [] || trim($term) === '') {
            return $query;
        }

        $driver = $query->getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return $query->whereRaw(
                'MATCH ('.implode(',', $columns).') AGAINST (? IN BOOLEAN MODE)',
                [$this->buildFulltextWildcards($term)]
            );
        }

        return $query->where(function (Builder $builder) use ($columns, $term): void {
            foreach ($columns as $column) {
                $builder->orWhere($column, 'LIKE', '%'.$term.'%');
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
