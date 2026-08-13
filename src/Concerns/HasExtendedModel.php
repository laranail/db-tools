<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Convenience aggregate over the four inheritance-friendly model traits:
 * {@see HasMergedFillable}, {@see HasMergedHidden}, {@see HasMergedCasts} and
 * {@see HasDefaultAttributes}.
 *
 * Apply it once on a base model and every subclass can extend the inherited
 * `$fillable`, `$hidden`, cast map and attribute defaults by declaring only its
 * own additions:
 *
 * ```php
 * abstract class User extends Model
 * {
 *     use HasExtendedModel;
 *
 *     protected $fillable = ['email', 'name'];
 * }
 *
 * class Member extends User
 * {
 *     protected function additionalFillable(): array
 *     {
 *         return ['credit_limit'];
 *     }
 *
 *     protected function additionalCasts(): array
 *     {
 *         return ['credit_limit' => 'integer'];
 *     }
 * }
 * ```
 *
 * The four are independent — apply them individually when you only need some.
 * This trait adds nothing of its own beyond the composition; in particular it
 * does **not** touch `$guarded`. An earlier form set `protected $guarded = []`,
 * which made every attribute mass-assignable on any model whose `$fillable` was
 * empty, and fataled outright on a model that declared its own `$guarded` with a
 * different value. Mass-assignment policy belongs to the model, not to a
 * composition trait.
 *
 * @phpstan-require-extends Model
 */
trait HasExtendedModel
{
    use HasDefaultAttributes;
    use HasMergedCasts;
    use HasMergedFillable;
    use HasMergedHidden;
}
