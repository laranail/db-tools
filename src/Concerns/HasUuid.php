<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Ramsey\Uuid\Uuid as RamseyUuid;
use Simtabi\Laranail\DbTools\Exceptions\MissingUuidColumnException;
use Simtabi\Laranail\DbTools\Support\SchemaColumnCache;

trait HasUuid
{
    use HasUuidOptions;

    /**
     * Boot trait on the model.
     *
     *
     * @throws Exception
     */
    public static function bootHasUuid(): void
    {
        static::creating(function ($model): void {
            if (! $model->isEnforceUuid()) {
                return;
            }

            $model->hasColumnUuid($model);

            // Only generate when no UUID was explicitly provided, so
            // caller-supplied values are preserved.
            $column = $model->getUuidColumnName();
            if (empty($model->{$column})) {
                $model->setUuid($model->getGeneratedUuid($model));
            }
        });

        static::updating(function ($model): void {
            if (! $model->isEnforceUuid()) {
                return;
            }

            // The UUID is immutable once persisted: restore the original
            // value if an update tries to change it.
            $column = $model->getUuidColumnName();
            $originalUuid = $model->getOriginal($column);

            if ($originalUuid !== null && $model->{$column} !== $originalUuid) {
                $model->setUuid($originalUuid);
            }
        });
    }

    /**
     * Forget the memoised column checks.
     */
    public static function flushColumnCache(): void
    {
        SchemaColumnCache::flush();
    }

    /**
     * Get the route key for the model.
     *
     * @return string
     */
    public function getRouteKeyName()
    {
        return $this->getUuidColumnName();
    }

    /**
     * Scope query by UUID.
     *
     * @param  string  $uuid
     * @param  bool  $firstOrFail
     * @return Model|Builder
     *
     * @throws ModelNotFoundException
     */
    public function scopeFindByUuid($query, $uuid, $firstOrFail = true)
    {
        $this->validateUuid($uuid);

        $queryBuilder = $query->where($this->getUuidColumnName(), $uuid);

        return $firstOrFail ? $queryBuilder->firstOrFail() : $queryBuilder;
    }

    /**
     * Check if the table have a column uuid.
     *
     * @param Model
     *
     * @throws MissingUuidColumnException
     */
    private function hasColumnUuid($model): void
    {
        $column = $model->getUuidColumnName();

        // Memoised: this ran a schema introspection query on EVERY insert, so
        // a bulk import of 10k rows issued 10k of them. The memo lives in a
        // shared class rather than a static here, because a trait static is
        // copied into every using class — a flush on the trait would clear a
        // copy nothing reads.
        if (! SchemaColumnCache::has($model, $column)) {
            throw new MissingUuidColumnException("You don't have a '{$column}' column on '{$model->getTable()}' table.");
        }
    }

    /**
     * Check if uuid value is valid.
     *
     * @param  string  $uuid
     *
     * @throws ModelNotFoundException
     */
    private function validateUuid($uuid): void
    {
        if (! RamseyUuid::isValid($uuid)) {
            throw (new ModelNotFoundException)->setModel($this::class);
        }
    }
}
