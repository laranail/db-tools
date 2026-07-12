<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Symfony\Component\Uid\Ulid;

/**
 * Auto-set a ULID on the configured column at creating time.
 *
 * ULIDs are 26-char Crockford-base32, lexicographically sortable by
 * creation time — useful for indexing, debugging, and pagination.
 *
 * Override `ulidColumn()` to customize.
 */
trait HasUlid
{
    public static function bootHasUlid(): void
    {
        static::creating(static function ($model): void {
            $column = method_exists($model, 'ulidColumn') ? $model->ulidColumn() : 'ulid';

            if (empty($model->{$column})) {
                $model->{$column} = (new Ulid)->toBase32();
            }
        });
    }

    public function ulidColumn(): string
    {
        return defined(static::class.'::ULID_COLUMN') ? static::ULID_COLUMN : 'ulid';
    }
}
