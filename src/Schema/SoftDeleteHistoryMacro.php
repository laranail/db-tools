<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

use Illuminate\Database\Schema\Blueprint;

/**
 * Schema macro: $table->softDeleteHistory().
 *
 * Builds the columns for the soft-delete history table written by
 * HasSoftDeletesWithUndo. Records one row per genuine soft-delete and per
 * restore:
 *
 *   - id
 *   - record_id / record_type    — id-type-aware polymorphic ref to the row
 *   - action      — 'deleted' | 'restored'
 *   - actor_id    — nullable; the acting user (null for guest/console/queue)
 *   - reason      — nullable free-text note
 *   - happened_at — when the event occurred
 *   - timestamps  — created_at / updated_at
 *
 * Registered in DbToolsServiceProvider::boot(); used by the publishable
 * create_soft_delete_history_table migration stub.
 *
 * Example:
 * ```
 * Schema::create('soft_delete_history', function (Blueprint $t) {
 *     $t->softDeleteHistory();
 * });
 * ```
 */
final class SoftDeleteHistoryMacro
{
    public static function register(): void
    {
        if (Blueprint::hasMacro('softDeleteHistory')) {
            return;
        }

        Blueprint::macro('softDeleteHistory', function (): Blueprint {
            /** @var Blueprint $this */
            $this->id();

            // Id-type-aware polymorphic `record_*` columns — mirrors what the
            // configuredMorphs() macro builds, inlined here so the history
            // table lines up with the configured key type without depending on
            // another macro being registered first.
            match (ConfiguredMorphsMacro::idType()) {
                'UUID' => $this->uuidMorphs('record'),
                'ULID' => $this->ulidMorphs('record'),
                default => $this->morphs('record'),
            };

            $this->string('action');
            // Id-type-aware actor reference (the acting user's key), matching the
            // configured key type like the `record_*` columns above.
            match (ConfiguredMorphsMacro::idType()) {
                'UUID' => $this->foreignUuid('actor_id')->nullable()->index(),
                'ULID' => $this->foreignUlid('actor_id')->nullable()->index(),
                default => $this->foreignId('actor_id')->nullable()->index(),
            };
            $this->text('reason')->nullable();
            $this->timestamp('happened_at')->index();
            $this->timestamps();

            return $this;
        });
    }
}
