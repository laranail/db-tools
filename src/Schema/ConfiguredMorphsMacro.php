<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;

/**
 * Schema macros: $table->configuredMorphs() / $table->configuredNullableMorphs().
 *
 * Id-type-aware polymorphic columns. Unlike Laravel's `morphs()` (always an
 * integer `*_id`), these pick `morphs`/`uuidMorphs`/`ulidMorphs` to match the
 * package's configured key type (`db-tools.id_type`), so polymorphic
 * foreign keys line up with models using HasUuidsOrIntegerIds. Works on the
 * standard `Schema::create()` Blueprint — no custom builder required.
 *
 * Example:
 * ```
 * Schema::create('comments', function (Blueprint $t) {
 *     $t->id();
 *     $t->configuredMorphs('commentable'); // uuid/ulid/int *_id + *_type
 * });
 * ```
 */
final class ConfiguredMorphsMacro
{
    public static function register(): void
    {
        if (! Blueprint::hasMacro('configuredMorphs')) {
            Blueprint::macro('configuredMorphs', function (string $name, ?string $indexName = null): void {
                /** @var Blueprint $this */
                match (ConfiguredMorphsMacro::idType()) {
                    'UUID' => $this->uuidMorphs($name, $indexName),
                    'ULID' => $this->ulidMorphs($name, $indexName),
                    default => $this->morphs($name, $indexName),
                };
            });
        }

        if (! Blueprint::hasMacro('configuredNullableMorphs')) {
            Blueprint::macro('configuredNullableMorphs', function (string $name, ?string $indexName = null): void {
                /** @var Blueprint $this */
                match (ConfiguredMorphsMacro::idType()) {
                    'UUID' => $this->nullableUuidMorphs($name, $indexName),
                    'ULID' => $this->nullableUlidMorphs($name, $indexName),
                    default => $this->nullableMorphs($name, $indexName),
                };
            });
        }
    }

    /**
     * Resolve the configured key type (BIGINT|UUID|ULID).
     *
     * Precedence: `using_uuids_for_id` (→ UUID) wins over `using_ulids_for_id`
     * (→ ULID), which win over the `id_type` string (default `BIGINT`). If both
     * boolean flags are true, UUID is used.
     */
    public static function idType(): string
    {
        if (config('db-tools.using_uuids_for_id', false)) {
            return 'UUID';
        }

        if (config('db-tools.using_ulids_for_id', false)) {
            return 'ULID';
        }

        return Str::upper((string) config('db-tools.id_type', 'BIGINT'));
    }
}
