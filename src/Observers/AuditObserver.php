<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Stamps `created_by` / `updated_by` / `deleted_by` from the authenticated
 * user. Pairs naturally with the `Blueprint::auditColumns()` schema macro.
 *
 * Attach by overriding the model's `boot()` (or in your service provider's
 * `boot()`):
 *
 *     Order::observe(AuditObserver::class);
 *
 * Override `userIdentifier($model)` if your foreign key is not the
 * authenticated user's primary key (e.g., a UUID column instead of `id`).
 */
class AuditObserver
{
    public function creating(Model $model): void
    {
        $actor = $this->userIdentifier($model);

        // No authenticated actor (guest / console / queue) — leave the
        // nullable audit columns untouched rather than stamping null.
        if ($actor === null) {
            return;
        }

        $createdBy = $this->auditColumn('created_by');
        $updatedBy = $this->auditColumn('updated_by');

        if ($this->modelHasColumn($model, $createdBy) && empty($model->{$createdBy})) {
            $model->{$createdBy} = $actor;
        }
        if ($this->modelHasColumn($model, $updatedBy) && empty($model->{$updatedBy})) {
            $model->{$updatedBy} = $actor;
        }
    }

    public function updating(Model $model): void
    {
        $actor = $this->userIdentifier($model);

        if ($actor === null) {
            return;
        }

        $updatedBy = $this->auditColumn('updated_by');

        if ($this->modelHasColumn($model, $updatedBy)) {
            $model->{$updatedBy} = $actor;
        }
    }

    public function deleting(Model $model): void
    {
        $actor = $this->userIdentifier($model);

        if ($actor === null) {
            return;
        }

        $deletedBy = $this->auditColumn('deleted_by');

        if (! $this->modelHasColumn($model, $deletedBy)) {
            return;
        }

        // Only stamp on a genuine soft-delete. A model without SoftDeletes is
        // hard-deleted (the row vanishes, so a stamp is pointless), and a
        // force-delete on a soft-deletable model likewise removes the row.
        if (! method_exists($model, 'isForceDeleting') || $model->isForceDeleting()) {
            return;
        }

        // Eloquent's runSoftDelete() only writes the `deleted_at` (and
        // `updated_at`) columns — it does NOT flush other dirty attributes — so
        // the actor stamp must be persisted explicitly. Write *only* the
        // deleted_by column with a targeted quiet update keyed on the primary
        // key, instead of saveQuietly() which would flush every dirty
        // attribute and could leave the model half-written. This update is part
        // of the delete flow, just before the soft-delete UPDATE runs.
        $model->{$deletedBy} = $actor;

        if ($model->exists && $model->getKey() !== null) {
            $model->newQuery()
                ->whereKey($model->getKey())
                ->update([$deletedBy => $actor]);

            // Keep the in-memory model in sync so it doesn't re-report the
            // column as dirty on subsequent saves.
            $model->syncOriginalAttribute($deletedBy);
        }
    }

    protected function userIdentifier(Model $model): mixed
    {
        return Auth::user()?->getAuthIdentifier();
    }

    /**
     * Resolve a configured audit column name, defaulting to the column's
     * conventional name when no override is present.
     */
    protected function auditColumn(string $key): string
    {
        $name = config("db-tools.audit.{$key}", $key);

        return is_string($name) && $name !== '' ? $name : $key;
    }

    protected function modelHasColumn(Model $model, string $column): bool
    {
        return in_array($column, $model->getFillable(), true)
            || array_key_exists($column, $model->getAttributes())
            || $model->isFillable($column);
    }
}
