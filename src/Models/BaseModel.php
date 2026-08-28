<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Models;

use Override;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Simtabi\Laranail\DbTools\Concerns\HasUuidsOrIntegerIds;

/**
 * Optional base Eloquent model providing common conveniences: UUID-or-integer
 * keys, timestamp casting, lifecycle hook stubs, time-based scopes, and small
 * metadata/serialization helpers.
 *
 * Soft deletes are intentionally NOT forced — add `SoftDeletes` (and a
 * `deleted_at` column) on the concrete model when you need them.
 *
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
abstract class BaseModel extends Model
{
    use HasUuidsOrIntegerIds;

    public const string CREATED_AT = 'created_at';

    public const string UPDATED_AT = 'updated_at';

    public $timestamps = true;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Scope to records created today.
     *
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate($this->timeScopeColumn(), today());
    }

    /**
     * Scope to records created this week.
     *
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    public function scopeThisWeek(Builder $query): Builder
    {
        return $query->whereBetween($this->timeScopeColumn(), [now()->startOfWeek(), now()->endOfWeek()]);
    }

    /**
     * Scope to records created this month.
     *
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    public function scopeThisMonth(Builder $query): Builder
    {
        return $query->whereMonth($this->timeScopeColumn(), now()->month)
            ->whereYear($this->timeScopeColumn(), now()->year);
    }

    /**
     * Scope to records created this year.
     *
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    public function scopeThisYear(Builder $query): Builder
    {
        return $query->whereYear($this->timeScopeColumn(), now()->year);
    }

    /**
     * Scope to records created within the last N days.
     *
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where($this->timeScopeColumn(), '>=', now()->subDays($days));
    }

    /**
     * The model's table name.
     */
    public function getTableName(): string
    {
        return $this->getTable();
    }

    /**
     * The model's short class name.
     */
    public function getModelName(): string
    {
        return class_basename($this);
    }

    /**
     * The model's fully-qualified class name.
     */
    public function getFullModelName(): string
    {
        return static::class;
    }

    /**
     * Whether the model has not yet been persisted.
     */
    public function isNew(): bool
    {
        return ! $this->exists;
    }

    /**
     * Whether the model has unsaved changes.
     */
    public function isModified(): bool
    {
        return $this->isDirty();
    }

    /**
     * Human-readable "created at" (e.g. "3 days ago").
     */
    public function getCreatedAtForHumans(): ?string
    {
        return $this->created_at?->diffForHumans();
    }

    /**
     * Human-readable "updated at" (e.g. "3 days ago").
     */
    public function getUpdatedAtForHumans(): ?string
    {
        return $this->updated_at?->diffForHumans();
    }

    /**
     * Array form of the model with an extra `_metadata` block.
     *
     * @return array<string, mixed>
     */
    public function toArrayWithMetadata(): array
    {
        $array = $this->toArray();

        $array['_metadata'] = [
            'model_name'       => $this->getModelName(),
            'table_name'       => $this->getTableName(),
            'is_new'           => $this->isNew(),
            'is_modified'      => $this->isModified(),
            'created_at_human' => $this->getCreatedAtForHumans(),
            'updated_at_human' => $this->getUpdatedAtForHumans(),
        ];

        return $array;
    }

    /**
     * Reload the model's attributes from the database.
     *
     * Global scopes are bypassed on purpose: reloading is about this specific
     * row, and a row that has stopped matching a scope is exactly the case
     * where stale in-memory values are most misleading. Reading through
     * static::query() meant such a row silently kept its old values while the
     * call still reported success.
     *
     * @throws ModelNotFoundException when the row no longer exists
     */
    public function reload(): static
    {
        if (! $this->exists) {
            return $this;
        }

        $fresh = $this->newQueryWithoutScopes()->whereKey($this->getKey())->first();

        if ($fresh === null) {
            throw (new ModelNotFoundException)->setModel(static::class, [$this->getKey()]);
        }

        $this->setRawAttributes($fresh->getAttributes());

        // Without this the reloaded values all counted as unsaved changes, so
        // isModified() lied about a model just read from the database.
        $this->syncOriginal();

        return $this;
    }

    /**
     * Boot the model and wire lifecycle hook stubs.
     */
    #[Override]
    protected static function boot(): void
    {
        parent::boot();

        static::creating(static fn (self $model) => $model->handleCreating());
        static::created(static fn (self $model) => $model->handleCreated());
        static::updating(static fn (self $model) => $model->handleUpdating());
        static::updated(static fn (self $model) => $model->handleUpdated());
        static::deleting(static fn (self $model) => $model->handleDeleting());
        static::deleted(static fn (self $model) => $model->handleDeleted());
    }

    /** Override in child classes if needed. */
    protected function handleCreating(): void {}

    /** Override in child classes if needed. */
    protected function handleCreated(): void {}

    /** Override in child classes if needed. */
    protected function handleUpdating(): void {}

    /** Override in child classes if needed. */
    protected function handleUpdated(): void {}

    /** Override in child classes if needed. */
    protected function handleDeleting(): void {}

    /** Override in child classes if needed. */
    protected function handleDeleted(): void {}

    /**
     * Column the time-based scopes operate on (defaults to the created-at column).
     */
    protected function timeScopeColumn(): string
    {
        return static::CREATED_AT;
    }
}
