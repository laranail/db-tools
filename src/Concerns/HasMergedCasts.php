<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Extend an inherited cast map instead of restating it.
 *
 * Apply the trait once on the base model; every subclass then declares only the
 * casts it adds, via {@see additionalCasts()}. Additions win over an inherited
 * cast for the same key, so a subclass can also *re-cast* a column.
 *
 * ## Why this hooks `getCasts()` and not `casts()`
 *
 * The obvious seam is Laravel's `casts()` method, but a trait method loses to a
 * method declared in the class body — silently, with no error. A base model
 * that declares `protected function casts(): array` (the idiomatic Laravel 11+
 * form) would therefore shadow the trait's merge, and every subclass's
 * `additionalCasts()` would simply never be consulted. Nothing fails loudly:
 * the columns just stop being cast.
 *
 * `getCasts()` has no such problem. Laravel folds `casts()` into the `$casts`
 * property once, in `initializeHasAttributes()`, and every casting decision —
 * `hasCast()`, `getCastType()`, `castAttribute()`, `attributesToArray()` —
 * reads `getCasts()`. Hooking there sees the class's own `casts()` already
 * merged in, so declaring `casts()` and using this trait compose correctly.
 *
 * @phpstan-require-extends Model
 */
trait HasMergedCasts
{
    /**
     * Casts this model adds to the ones it inherits.
     *
     * Override in a subclass. An entry here overrides an inherited cast for the
     * same attribute.
     *
     * @return array<string, string>
     */
    protected function additionalCasts(): array
    {
        return [];
    }

    /**
     * The inherited cast map plus this model's additions.
     *
     * @return array<string, string>
     */
    public function getCasts(): array
    {
        return array_merge(parent::getCasts(), $this->additionalCasts());
    }
}
