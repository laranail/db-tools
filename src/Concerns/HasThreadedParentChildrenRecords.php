<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Adjacency-list parent/child threading for self-referential models.
 *
 * Column names are configurable so the trait is reusable across schemas:
 * override {@see parentKeyColumn()}, {@see threadScopeColumn()} or
 * {@see threadOrderColumn()} (or set the matching properties) per model.
 */
trait HasThreadedParentChildrenRecords
{
    /**
     * Column holding the parent reference (default `parent_id`).
     */
    public function parentKeyColumn(): string
    {
        return property_exists($this, 'parentKeyColumn') ? $this->parentKeyColumn : 'parent_id';
    }

    /**
     * Optional column scoping a thread to an owner (e.g. `ticket_id`).
     * Return null to thread across the whole table.
     */
    public function threadScopeColumn(): ?string
    {
        return property_exists($this, 'threadScopeColumn') ? $this->threadScopeColumn : null;
    }

    /**
     * Column used to order siblings (default `created_at`).
     */
    public function threadOrderColumn(): string
    {
        return property_exists($this, 'threadOrderColumn') ? $this->threadOrderColumn : 'created_at';
    }

    /**
     * The direct parent record.
     *
     * @return BelongsTo<static, static>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, $this->parentKeyColumn());
    }

    /**
     * The direct children, ordered.
     *
     * @return HasMany<static, static>
     */
    public function children(): HasMany
    {
        $relation = $this->hasMany(static::class, $this->parentKeyColumn())
            ->orderBy($this->threadOrderColumn());

        // Carry the thread scope down the tree. Matching on the parent key
        // alone pulled in any row pointing at this record — reparented,
        // imported, or written with a stale id — regardless of which thread it
        // belonged to. getAsThreadedParentToChildren() scoped only its ROOT
        // query, so those rows still arrived through the eager-loaded
        // descendants. Where the scope column stands in for a tenant, that is
        // a cross-tenant read.
        $scopeColumn = $this->threadScopeColumn();

        // Only when this instance actually carries a scope value. Eager loading
        // builds the relation from a FRESH instance, where the attribute is
        // null — constraining on that would match nothing. Eager tree loading
        // is scoped explicitly instead, in
        // getAsThreadedParentToChildren().
        if ($scopeColumn !== null && $this->getAttribute($scopeColumn) !== null) {
            $relation->where($scopeColumn, $this->getAttribute($scopeColumn));
        }

        return $relation;
    }

    /**
     * The children with their full descendant tree eager-loaded.
     *
     * @return HasMany<static, static>
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    /**
     * Fetch root records (optionally scoped) with their threaded descendants.
     *
     * @return Collection<int, static>
     */
    public function getAsThreadedParentToChildren(int|string|null $scopeId = null): Collection
    {
        $query = static::query()->whereNull($this->parentKeyColumn());

        $scopeColumn = $this->threadScopeColumn();
        $scoped = $scopeColumn !== null && $scopeId !== null;

        if ($scoped) {
            $query->where($scopeColumn, $scopeId);
        }

        // Apply the scope at EVERY level of the tree, not just the roots. The
        // root query was scoped but the eager-loaded descendants were not, so
        // a row pointing at a record in another thread arrived anyway — a
        // cross-tenant read wherever the scope column stands in for a tenant.
        $constrain = null;
        $constrain = static function ($relation) use ($scopeColumn, $scopeId, $scoped, &$constrain): void {
            if ($scoped) {
                $relation->where($scopeColumn, $scopeId);
            }

            $relation->with(['descendants' => $constrain]);
        };

        return $query->with(['descendants' => $constrain])
            ->orderBy($this->threadOrderColumn())
            ->get();
    }

    /**
     * Whether this record has any children.
     */
    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }

    /**
     * Whether this record is a root (has no parent).
     */
    public function isParent(): bool
    {
        return $this->getAttribute($this->parentKeyColumn()) === null;
    }
}
