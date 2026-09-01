<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\Sluggable\HasSlug as SpatieHasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * Opinionated wrapper around spatie/laravel-sluggable with configurable
 * source/destination columns plus slug lookup helpers.
 *
 * Override the source/destination either with the `$slugSrcInputName` /
 * `$slugDestColumnName` properties or `setSlugSrcInputName()` /
 * `setSlugDestColumnName()` methods.
 */
trait HasSlug
{
    use SpatieHasSlug;

    /**
     * Return a unique variant of the slug if it already exists.
     *
     * WARNING — this is a best-effort, check-then-append helper and is subject
     * to a time-of-check/time-of-use race: two requests can both see the slug
     * as free and then both write it. For guaranteed uniqueness, rely on
     * spatie/laravel-sluggable's built-in unique-slug generation (the default
     * behaviour configured in getSlugOptions(), which runs at save time) and
     * enforce a UNIQUE index on the slug column at the database level. This
     * method is retained for callers that need an ad-hoc candidate slug, not as
     * a uniqueness guarantee.
     */
    public static function checkModelSlug(string $slug): string
    {
        return static::slugExists($slug) ? $slug.'-'.Str::lower((string) Str::ulid()) : $slug;
    }

    /**
     * Whether a record with the given slug already exists.
     *
     * Passing null uses the model's configured destination column. These
     * helpers used to default to a literal 'slug', ignoring
     * getSlugDestColumnName(), so on a model that stores its slug elsewhere
     * they queried a column that does not exist — or, worse, on a table that
     * also happens to carry a 'slug' column, quietly answered about the wrong
     * one.
     */
    public static function slugExists(string $slug, ?string $columnName = null): bool
    {
        return static::withoutGlobalScopes()
            ->where($columnName ?? (new static)->getSlugDestColumnName(), $slug)
            ->exists();
    }

    /**
     * Build the spatie slug options from the configured columns.
     *
     * Uniqueness is delegated to spatie/laravel-sluggable, which appends a
     * numeric suffix (foo, foo-1, foo-2, …) at save time. That generation runs
     * inside the model's save and is the canonical way to keep slugs unique —
     * prefer it over the static checkModelSlug() helper, and back it with a DB
     * unique index on the slug column to close the check-then-write race under
     * concurrency.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom($this->getSlugSrcInputName())
            ->saveSlugsTo($this->getSlugDestColumnName());
    }

    /**
     * Resolve the slug source column.
     */
    public function getSlugSrcInputName(): string
    {
        if (method_exists($this, 'setSlugSrcInputName')) {
            return $this->setSlugSrcInputName();
        }

        // property_exists, not a trait property with a default: PHP forbids
        // redeclaring the latter with a different value, so the documented
        // `protected string $slugSrcInputName = 'title';` on a model was a
        // fatal error rather than a configuration.
        return property_exists($this, 'slugSrcInputName') ? $this->slugSrcInputName : 'name';
    }

    /**
     * Resolve the slug destination column.
     */
    public function getSlugDestColumnName(): string
    {
        if (method_exists($this, 'setSlugDestColumnName')) {
            return $this->setSlugDestColumnName();
        }

        return property_exists($this, 'slugDestColumnName') ? $this->slugDestColumnName : 'slug';
    }

    /**
     * Scope a query to a slug value.
     *
     * Passing null uses the model's configured destination column.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBySlug(Builder $query, string $slug, ?string $columnName = null): Builder
    {
        return $query->where($columnName ?? $this->getSlugDestColumnName(), $slug);
    }
}
