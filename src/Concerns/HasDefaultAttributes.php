<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Declare default attribute values in a method, so subclasses can extend the
 * inherited defaults instead of restating them.
 *
 * Laravel's own mechanism is the `$attributes` property, which a subclass can
 * only replace wholesale — redeclaring it drops every inherited default. This
 * trait adds a method seam alongside it: {@see additionalAttributes()} tops up
 * whatever `$attributes` already provides, without touching it.
 *
 * ```php
 * class Member extends User
 * {
 *     protected function additionalAttributes(): array
 *     {
 *         return ['credit_limit' => 2];
 *     }
 * }
 *
 * (new Member)->credit_limit; // 2 — before any save
 * ```
 *
 * ## Where the defaults are applied
 *
 * In `initializeHasDefaultAttributes()`, the per-trait initializer Laravel runs
 * from the model constructor. That timing is the whole point:
 *
 * - It lands **before** `syncOriginal()`, so a default is part of the model's
 *   original state and never shows up in `getDirty()`. Applying defaults later
 *   (in a `creating` hook, or worse by overriding `getAttributes()`) makes every
 *   untouched model look dirty and provokes phantom `UPDATE`s.
 * - It lands **before** `fill()`, so constructor input still wins — including an
 *   explicit `null`. Only keys genuinely absent get a default; the test is
 *   `array_key_exists()`, not `isset()`, so a deliberate `null` is preserved
 *   rather than being overwritten on every read.
 * - Hydration is unaffected. `newFromBuilder()` replaces the attribute array
 *   wholesale via `setRawAttributes()`, so a row loaded from the database is
 *   never retro-fitted with defaults — what is stored is what you get.
 *
 * The one thing it cannot cover, exactly like Laravel's `$attributes`, is a
 * query-builder `insert()`, which never instantiates a model.
 *
 * @phpstan-require-extends Model
 */
trait HasDefaultAttributes
{
    /**
     * Default values this model adds to the ones it inherits from `$attributes`.
     *
     * Override in a subclass. Call `parent::additionalAttributes()` when the
     * parent also defines some and you mean to extend rather than replace them.
     *
     * @return array<string, mixed>
     */
    protected function additionalAttributes(): array
    {
        return [];
    }

    /**
     * Seed the declared defaults for keys `$attributes` has not already set.
     */
    protected function initializeHasDefaultAttributes(): void
    {
        foreach ($this->additionalAttributes() as $key => $value) {
            if (! array_key_exists($key, $this->attributes)) {
                $this->attributes[$key] = $value;
            }
        }
    }
}
