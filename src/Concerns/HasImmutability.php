<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Simtabi\Laranail\DbTools\Exceptions\ImmutableDataException;

/**
 * Prevent updates and deletes on models flagged as immutable.
 *
 * Models become immutable by default once this trait is applied; override
 * {@see isImmutable()} to make immutability conditional.
 */
trait HasImmutability
{
    /**
     * Boot the trait: block updates and deletes on immutable models.
     */
    public static function bootHasImmutability(): void
    {
        static::updating(function ($model): void {
            if ($model->isImmutable()) {
                throw ImmutableDataException::forModel($model);
            }
        });

        static::deleting(function ($model): void {
            if ($model->isImmutable()) {
                throw ImmutableDataException::forModel($model);
            }
        });
    }

    /**
     * Whether the model is currently immutable (defaults to true).
     */
    public function isImmutable(): bool
    {
        return true;
    }
}
