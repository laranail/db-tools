<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Schema\Scopes\ArchiveScope;

/**
 * Adds soft-archive support to an Eloquent model, keyed off an `archived_at`
 * column. Laravel's native soft deletes occupy `deleted_at`, so archiving and
 * deleting can coexist on the same model.
 *
 * Add the column in a migration (e.g. `$table->timestamp('archived_at')->nullable()`).
 *
 * @method static Builder<static> withArchived(bool $withArchived = true)
 * @method static Builder<static> onlyArchived()
 * @method static Builder<static> withoutArchived()
 *
 * @phpstan-require-extends Model
 */
trait HasArchiver
{
    /**
     * Indicates if the model should use archives.
     */
    public bool $archives = true;

    /**
     * Boot the archiving trait for a model.
     */
    public static function bootHasArchiver(): void
    {
        static::addGlobalScope(new ArchiveScope);
    }

    /**
     * Initialise the archiving trait for an instance: cast the archive column.
     */
    public function initializeHasArchiver(): void
    {
        if (! isset($this->casts[$this->getArchivedAtColumn()])) {
            $this->casts[$this->getArchivedAtColumn()] = 'datetime';
        }
    }

    /**
     * Archive the model by stamping the archive column.
     */
    public function archive(): ?bool
    {
        if (! $this->exists) {
            return null;
        }

        if ($this->fireModelEvent('archiving') === false) {
            return false;
        }

        $this->touchOwners();

        $this->runArchive();

        $this->fireModelEvent('archived', false);

        return true;
    }

    /**
     * Perform the actual archive query on this model instance.
     */
    public function runArchive(): void
    {
        $query = $this->setKeysForSaveQuery($this->newModelQuery());

        $time = $this->freshTimestamp();

        $columns = [$this->getArchivedAtColumn() => $this->fromDateTime($time)];

        $this->{$this->getArchivedAtColumn()} = $time;

        if ($this->usesTimestamps() && $this->getUpdatedAtColumn() !== null) {
            $this->{$this->getUpdatedAtColumn()} = $time;

            $columns[$this->getUpdatedAtColumn()] = $this->fromDateTime($time);
        }

        $query->update($columns);

        $this->syncOriginalAttributes(array_keys($columns));
    }

    /**
     * Restore an archived model.
     */
    public function unArchive(): ?bool
    {
        if ($this->fireModelEvent('unArchiving') === false) {
            return false;
        }

        $this->{$this->getArchivedAtColumn()} = null;

        $this->exists = true;

        $result = $this->save();

        $this->fireModelEvent('unArchived', false);

        return $result;
    }

    /**
     * Determine if the model instance has been archived.
     */
    public function isArchived(): bool
    {
        return $this->{$this->getArchivedAtColumn()} !== null;
    }

    /**
     * Register an "archiving" model event callback with the dispatcher.
     */
    public static function archiving(Closure|string $callback): void
    {
        static::registerModelEvent('archiving', $callback);
    }

    /**
     * Register an "archived" model event callback with the dispatcher.
     */
    public static function archived(Closure|string $callback): void
    {
        static::registerModelEvent('archived', $callback);
    }

    /**
     * Register an "un-archiving" model event callback with the dispatcher.
     */
    public static function unArchiving(Closure|string $callback): void
    {
        static::registerModelEvent('unArchiving', $callback);
    }

    /**
     * Register an "un-archived" model event callback with the dispatcher.
     */
    public static function unArchived(Closure|string $callback): void
    {
        static::registerModelEvent('unArchived', $callback);
    }

    /**
     * Get the name of the "archived at" column.
     */
    public function getArchivedAtColumn(): string
    {
        return defined('static::ARCHIVED_AT') ? static::ARCHIVED_AT : 'archived_at';
    }

    /**
     * Get the fully qualified "archived at" column.
     */
    public function getQualifiedArchivedAtColumn(): string
    {
        return $this->qualifyColumn($this->getArchivedAtColumn());
    }
}
