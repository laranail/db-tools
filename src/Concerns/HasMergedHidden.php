<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Extend an inherited `$hidden` list instead of restating it.
 *
 * The counterpart to {@see HasMergedFillable}, for serialization. Apply the
 * trait once on the base model; every subclass then declares only the extra
 * attributes it needs to keep out of `toArray()` / `toJson()`, via
 * {@see additionalHidden()}.
 *
 * This matters more than fillable does: a subclass that restates `$hidden` and
 * forgets one inherited entry silently starts leaking it. Merging makes that
 * impossible — a subclass can add to the hidden set but never shrink it.
 *
 * @phpstan-require-extends Model
 */
trait HasMergedHidden
{
    /**
     * The inherited hidden list plus this model's additions.
     *
     * @return array<int, string>
     */
    public function getHidden(): array
    {
        return array_values(array_unique([
            ...parent::getHidden(),
            ...$this->additionalHidden(),
        ]));
    }

    /**
     * Attributes this model hides on top of the ones it inherits.
     *
     * Override in a subclass.
     *
     * @return array<int, string>
     */
    protected function additionalHidden(): array
    {
        return [];
    }
}
