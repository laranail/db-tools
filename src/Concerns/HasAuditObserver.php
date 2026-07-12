<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Simtabi\Laranail\DbTools\Observers\AuditObserver;

/**
 * Attaches {@see AuditObserver} to the model so `created_by` / `updated_by` /
 * `deleted_by` are stamped from the authenticated user.
 *
 * Canonical native alternative — prefer this on Laravel 8+ when you control the
 * class declaration:
 *
 *     use Illuminate\Database\Eloquent\Attributes\ObservedBy;
 *
 *     #[ObservedBy(AuditObserver::class)]
 *     class Order extends Model { ... }
 *
 * This trait exists for cases where the attribute is awkward (e.g. attaching an
 * observer to a model you do not declare, or wiring it conditionally).
 *
 * Boot-order caveat: HasEvents boots traits via `boot{TraitName}()`, which runs
 * during the model's `boot()` — after any `#[ObservedBy]` attribute observers
 * are registered, but the relative order of multiple booting traits follows
 * `class_uses_recursive()` order. If another trait's boot hook must run before
 * or after this observer, register the observer explicitly rather than relying
 * on trait boot order.
 */
trait HasAuditObserver
{
    public static function bootHasAuditObserver(): void
    {
        // Defer until the model is fully booted. Calling observe() during
        // boot() trips Eloquent's boot guard (observe() does `new static`,
        // which re-enters booting). This mirrors how HasEvents registers
        // #[ObservedBy] observers via static::whenBooted(...).
        static::whenBooted(fn () => static::observe(AuditObserver::class));
    }
}
