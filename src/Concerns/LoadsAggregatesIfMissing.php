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
     * @param array<int, string>|string $relations
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
     * @param array<int, string>|string $relations
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
     * @param array<int, string>|string $relations
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
     * Eloquent accepts two spellings, and both must survive: a plain list of
     * relation names, and a keyed array mapping a relation to a closure that
     * constrains it. The filter used to run over the VALUES with a
     * string-typed callback, so under strict_types the constrained form —
     * which the parent methods document and pass straight through — raised a
     * TypeError instead of loading anything. Keys and closures are preserved
     * so whatever is kept can be handed to Eloquent unchanged.
     *
     * @param array<array-key, string|callable> $relations
     *
     * @return array<array-key, string|callable>
     */
    protected function aggregatesMissing(array $relations, string $function, ?string $column = null): array
    {
        $missing = [];

        foreach ($relations as $key => $value) {
            $relation = is_string($key) ? $key : $value;

            if (! is_string($relation)) {
                continue;
            }

            // Mirror Eloquent's attribute naming (withAggregate):
            // snake("{relation} {function} {column}") for column aggregates,
            // "{relation}_count" for plain counts. An alias after " as " names
            // the attribute directly.
            $alias = str_contains($relation, ' as ')
                ? trim(explode(' as ', $relation)[1])
                : null;

            $name = $alias ?? ($column === null
                ? "{$relation}_{$function}"
                : "{$relation}_{$function}_{$column}");

            $attribute = (string) str(str_replace('.', '_', $name))->snake();

            if (array_key_exists($attribute, $this->getAttributes())) {
                continue;
            }

            if (is_string($key)) {
                $missing[$key] = $value;
            } else {
                $missing[] = $value;
            }
        }

        return $missing;
    }
}
