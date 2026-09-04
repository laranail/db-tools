<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

use Illuminate\Database\Schema\Blueprint;

/**
 * Schema macro: $table->auditColumns(...).
 *
 * Adds `created_by`, `updated_by`, `deleted_by` foreign-id-style columns
 * (nullable, indexed). Pairs with AuditObserver.
 *
 * The macro is registered in DbToolsServiceProvider::boot().
 *
 * Example:
 * ```
 * Schema::create('orders', function (Blueprint $t) {
 *     $t->id();
 *     $t->string('name');
 *     $t->auditColumns();
 *     $t->timestamps();
 *     $t->softDeletes();
 * });
 * ```
 *
 * Optional named-arguments:
 *   - foreignKey   — type of FK column. Defaults to the configured key type
 *                    ({@see ConfiguredMorphsMacro::idType()} → foreignId /
 *                    foreignUuid / foreignUlid). Pass an explicit value to override.
 *   - includeDeletedBy — set false to skip the deleted_by column when
 *                        the table doesn't use soft-deletes.
 *   - userTable    — referenced table name. Default 'users'. (No actual
 *                    FK constraint added by default; pass true via
 *                    constrained=true if you want one.)
 */
final class AuditColumnsMacro
{
    public static function register(): void
    {
        if (Blueprint::hasMacro('auditColumns')) {
            return;
        }

        Blueprint::macro('auditColumns', function (
            ?string $foreignKey = null,
            bool $includeDeletedBy = true,
            string $userTable = 'users',
            bool $constrained = false,
        ): Blueprint {
            /** @var Blueprint $this */
            // Default to the configured key type so the no-arg call works on
            // UUID/ULID apps; an explicit $foreignKey still overrides.
            $foreignKey ??= match (ConfiguredMorphsMacro::idType()) {
                'UUID'  => 'foreignUuid',
                'ULID'  => 'foreignUlid',
                default => 'foreignId',
            };

            $columns = ['created_by', 'updated_by'];
            if ($includeDeletedBy) {
                $columns[] = 'deleted_by';
            }

            foreach ($columns as $col) {
                $column = $this->{$foreignKey}($col)->nullable()->index();
                if ($constrained) {
                    $column->constrained($userTable)->nullOnDelete();
                }
            }

            return $this;
        });
    }
}
