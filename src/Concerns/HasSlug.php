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
     * Slug source column.
     */
    protected string $slugSrcInputName = 'name';

    /**
     * Slug destination column.
     */
    protected string $slugDestColumnName = 'slug';

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
        return method_exists($this, 'setSlugSrcInputName')
            ? $this->setSlugSrcInputName()
            : $this->slugSrcInputName;
    }

    /**
     * Resolve the slug destination column.
     */
    public function getSlugDestColumnName(): string
    {
        return method_exists($this, 'setSlugDestColumnName')
            ? $this->setSlugDestColumnName()
            : $this->slugDestColumnName;
    }

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
        return self::slugExists($slug) ? $slug.'-'.Str::lower((string) Str::ulid()) : $slug;
    }

    /**
     * Whether a record with the given slug already exists.
     */
    public static function slugExists(string $slug, string $columnName = 'slug'): bool
    {
        return static::withoutGlobalScopes()->where($columnName, $slug)->exists();
    }

    /**
     * Scope a query to a slug value.
     */
    public function scopeBySlug(Builder $query, string $slug, string $columnName = 'slug'): Builder
    {
        return $query->where($columnName, $slug);
    }
}
