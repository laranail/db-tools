<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Illuminate\Support\Str;

trait HasUuidsOrIntegerIds
{
    public static function bootHasUuidsOrIntegerIds(): void
    {
        static::creating(static function (self $model): void {
            if (static::isUsingIntegerId()) {
                return;
            }

            $model->{$model->getKeyName()} = $model->newUniqueId();
        });
    }

    public function newUniqueId(): ?string
    {
        return match (static::getTypeOfId()) {
            'ULID' => (string) Str::ulid(),
            'BIGINT' => null,
            default => (string) Str::orderedUuid(),
        };
    }

    public function getKeyType(): string
    {
        if (static::isUsingStringId()) {
            return 'string';
        }

        return $this->keyType;
    }

    public function getIncrementing(): bool
    {
        if (static::isUsingStringId()) {
            return false;
        }

        return $this->incrementing;
    }

    public static function determineIfUsingUuidsForId(): bool
    {
        return static::getTypeOfId() === 'UUID';
    }

    public static function determineIfUsingUlidsForId(): bool
    {
        return static::getTypeOfId() === 'ULID';
    }

    public static function getTypeOfId(): string
    {
        if (config('db-tools.using_uuids_for_id', false)) {
            return 'UUID';
        }

        if (config('db-tools.using_ulids_for_id', false)) {
            return 'ULID';
        }

        return Str::upper((string) config('db-tools.id_type', 'BIGINT'));
    }

    public function ensureIdCanBeCreated(): void
    {
        if (static::getTypeOfId() !== 'BIGINT') {
            $this->{$this->getKeyName()} = $this->newUniqueId();
        }
    }

    public static function isUsingIntegerId(): bool
    {
        return static::getTypeOfId() === 'BIGINT';
    }

    public static function isUsingStringId(): bool
    {
        return ! static::isUsingIntegerId();
    }
}
