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
     * Whether the archive scope should hide archived rows for this model.
     *
     * Nothing used to read $archives at all, so the documented opt-out had no
     * effect and archived rows were hidden regardless.
     */
    public function usesArchiving(): bool
    {
        // property_exists, not a trait property with a default: PHP forbids
        // redeclaring the latter with a different value, so
        // `public bool $archives = false;` on a model was a fatal error rather
        // than a configuration. Nothing read $archives at all before 0.6.0.
        return property_exists($this, 'archives') ? (bool) $this->archives : true;
    }

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

        // runArchive() used to discard the UPDATE's affected-row count, so a
        // model whose row had since been deleted still reported success, fired
        // the "archived" event and stamped the attribute for a row that was
        // never touched.
        if ($this->runArchive() === 0) {
            return false;
        }

        $this->fireModelEvent('archived', false);

        return true;
    }

    /**
     * Perform the actual archive query on this model instance.
     *
     * @return int the number of rows the update matched
     */
    public function runArchive(): int
    {
        $query = $this->setKeysForSaveQuery($this->newModelQuery());

        $time = $this->freshTimestamp();

        $columns = [$this->getArchivedAtColumn() => $this->fromDateTime($time)];

        $this->{$this->getArchivedAtColumn()} = $time;

        if ($this->usesTimestamps() && $this->getUpdatedAtColumn() !== null) {
            $this->{$this->getUpdatedAtColumn()} = $time;

            $columns[$this->getUpdatedAtColumn()] = $this->fromDateTime($time);
        }

        $affected = $query->update($columns);

        $this->syncOriginalAttributes(array_keys($columns));

        return $affected;
    }

    /**
     * Restore an archived model.
     */
    public function unArchive(): ?bool
    {
        // archive() guards on this; unArchive() did not, and set exists = true
        // unconditionally — so an unsaved model issued an UPDATE against a row
        // that does not exist and reported success.
        if (! $this->exists) {
            return null;
        }

        if ($this->fireModelEvent('unArchiving') === false) {
            return false;
        }

        $this->{$this->getArchivedAtColumn()} = null;

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
