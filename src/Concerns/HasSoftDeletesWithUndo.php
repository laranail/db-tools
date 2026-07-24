<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runtime companion to the `Blueprint::softDeletesWithUndo()` schema macro.
 *
 * Assumes the model uses Laravel's {@see SoftDeletes}
 * trait and the `softDeletesWithUndo()` columns (`deleted_at`, `restored_at`).
 *
 * On every genuine soft-delete and every restore it records a row in the
 * configured history table (`db-tools.soft_delete_history.table`, built
 * by the `Blueprint::softDeleteHistory()` macro) and, on restore, stamps the
 * model's `restored_at` column with the current time.
 *
 * The actor is resolved from {@see Auth::id()} and is nullable so guest,
 * console and queue writes succeed — mirroring AuditObserver.
 *
 * @mixin Model
 */
trait HasSoftDeletesWithUndo
{
    public static function bootHasSoftDeletesWithUndo(): void
    {
        static::deleted(static function (Model $model): void {
            // Only a genuine soft-delete leaves the row in place; a force-delete
            // (or a non-soft-deletable model) removes it, so a history row would
            // dangle. Skip those.
            if (! method_exists($model, 'isForceDeleting') || $model->isForceDeleting()) {
                return;
            }

            /** @var HasSoftDeletesWithUndo $model */
            $model->recordSoftDeleteHistory('deleted');
        });

        static::restored(static function (Model $model): void {
            /** @var HasSoftDeletesWithUndo $model */
            $model->stampRestoredAt();
            $model->recordSoftDeleteHistory('restored');
        });
    }

    /**
     * Restore the model and ensure a history row is written.
     *
     * Equivalent to a native {@see SoftDeletes::restore()} — the `restored`
     * event registered above does the stamping and history write — but exposed
     * as an explicit, discoverable entry point.
     */
    public function restoreWithHistory(): bool
    {
        /** @var Model&SoftDeletes $this */
        return (bool) $this->restore();
    }

    /**
     * Query the history rows recorded for this record, newest first.
     *
     * Returns a base query builder over the history table (the table has no
     * dedicated model), already scoped to this record's morph class and key.
     */
    public function softDeleteHistory(): Builder
    {
        /** @var Model $this */
        return DB::connection($this->getConnectionName())
            ->table($this->softDeleteHistoryTable())
            ->where('record_type', $this->getMorphClass())
            ->where('record_id', $this->getKey())
            ->orderByDesc('happened_at');
    }

    /**
     * Stamp the `restored_at` column with the current time, writing only that
     * single column quietly so no further model events fire.
     */
    protected function stampRestoredAt(): void
    {
        /** @var Model $this */
        if (! $this->exists || $this->getKey() === null) {
            return;
        }

        $this->setAttribute('restored_at', $this->freshTimestamp());
        $this->saveQuietly();
    }

    /**
     * Record a soft-delete history row inside a transaction.
     *
     * @param  'deleted'|'restored'  $action
     */
    protected function recordSoftDeleteHistory(string $action, ?string $reason = null): void
    {
        /** @var Model $this */
        if (! $this->exists || $this->getKey() === null) {
            return;
        }

        $now = $this->freshTimestamp();

        $row = [
            'record_type' => $this->getMorphClass(),
            'record_id' => $this->getKey(),
            'action' => $action,
            'actor_id' => $this->softDeleteHistoryActor(),
            'reason' => $reason,
            'happened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        try {
            DB::connection($this->getConnectionName())->transaction(function () use ($row): void {
                DB::connection($this->getConnectionName())
                    ->table($this->softDeleteHistoryTable())
                    ->insert($row);
            });
        } catch (Throwable) {
            // History is best-effort metadata; never let a logging failure
            // break the delete/restore the caller actually requested.
        }
    }

    /**
     * Resolve the acting user identifier. Nullable for guest/console/queue
     * contexts — mirrors AuditObserver.
     */
    protected function softDeleteHistoryActor(): int|string|null
    {
        /** @var int|string|null $id */
        $id = Auth::id();

        return $id;
    }

    /**
     * Resolve the configured history table name.
     */
    protected function softDeleteHistoryTable(): string
    {
        $table = config('laranail.db-tools.soft_delete_history.table', 'soft_delete_history');

        return is_string($table) && $table !== '' ? $table : 'soft_delete_history';
    }
}
