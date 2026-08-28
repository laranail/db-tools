<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Extend an inherited `$fillable` list instead of restating it.
 *
 * Apply the trait once, on the base model. Every subclass then declares only
 * what it adds, via {@see additionalFillable()} — the inherited list is picked
 * up automatically. Re-applying the trait further down the chain is allowed and
 * composes: each level merges onto the one above it.
 *
 * Duplicates are removed and the result is re-indexed, so a subclass repeating
 * an inherited column is harmless.
 *
 * @phpstan-require-extends Model
 */
trait HasMergedFillable
{
    /**
     * The inherited fillable list plus this model's additions.
     *
     * @return array<int, string>
     */
    public function getFillable(): array
    {
        return array_values(array_unique([
            ...parent::getFillable(),
            ...$this->additionalFillable(),
        ]));
    }

    /**
     * Mass-assignable attributes this model adds to the ones it inherits.
     *
     * Override in a subclass. Keep it pure — it is consulted on every `fill()`.
     *
     * @return array<int, string>
     */
    protected function additionalFillable(): array
    {
        return [];
    }
}
