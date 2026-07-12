<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Opt-in conveniences over Eloquent's native lazy-eager-loading helpers.
 *
 * Eloquent already ships {@see Model::loadMissing()} (loads relations only when
 * absent). This trait adds the same "skip work already done" idea to the
 * *aggregate* loaders — {@see Model::loadCount()} and
 * {@see Model::loadAggregate()} — which otherwise always re-run their queries
 * even when the `*_count` / aggregate attribute is already present.
 *
 * Pure delegation: nothing here changes how loading works, it only short-
 * circuits when the resulting attribute is already set. Opt-in per model; not
 * added to BaseModel.
 *
 * @mixin Model
 */
trait LoadsAggregatesIfMissing
{
    /**
     * Eager-load the given relations only when they are not already loaded.
     *
     * Thin alias for the native {@see Model::loadMissing()} — provided for
     * naming symmetry with the count/aggregate helpers below.
     *
     * @param  array<int, string>|string  $relations
     */
    public function loadIfMissing(array|string $relations): static
    {
        $this->loadMissing($relations);

        return $this;
    }

    /**
     * Load relationship counts only for relations whose `{relation}_count`
     * attribute is not already set, delegating those to native
     * {@see Model::loadCount()}.
     *
     * @param  array<int, string>|string  $relations
     */
    public function loadCountIfMissing(array|string $relations): static
    {
        $missing = $this->aggregatesMissing((array) $relations, 'count');

        if ($missing !== []) {
            $this->loadCount($missing);
        }

        return $this;
    }

    /**
     * Load a relationship aggregate only when its corresponding attribute is
     * missing, delegating to native {@see Model::loadAggregate()}.
     *
     * @param  array<int, string>|string  $relations
     */
    public function loadAggregateIfMissing(array|string $relations, string $column, string $function = 'count'): static
    {
        $missing = $this->aggregatesMissing((array) $relations, $function, $column);

        if ($missing !== []) {
            $this->loadAggregate($missing, $column, $function);
        }

        return $this;
    }

    /**
     * Filter the given relations down to those whose aggregate attribute is not
     * yet present on the model.
     *
     * @param  array<int, string>  $relations
     * @return array<int, string>
     */
    protected function aggregatesMissing(array $relations, string $function, ?string $column = null): array
    {
        return array_values(array_filter($relations, function (string $relation) use ($function, $column): bool {
            // Mirror Eloquent's attribute naming (withAggregate):
            // snake("{relation} {function} {column}") for column aggregates,
            // "{relation}_count" for plain counts.
            $name = $column === null
                ? "{$relation}_{$function}"
                : "{$relation}_{$function}_{$column}";

            $attribute = (string) str(str_replace('.', '_', $name))->snake();

            return ! array_key_exists($attribute, $this->getAttributes());
        }));
    }
}
