<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

use Illuminate\Database\Schema\Blueprint;

/**
 * Schema macro: $table->softDeletesWithUndo().
 *
 * Adds Laravel's standard `deleted_at` plus a `restored_at` companion
 * column that pairs with HasSoftDeletesWithUndo (0.x) to track the most
 * recent restoration timestamp. This macro ships only the columns; the
 * runtime restore-history table is provided by softDeleteHistory().
 *
 * Example:
 * ```
 * Schema::create('orders', function (Blueprint $t) {
 *     $t->id();
 *     $t->string('name');
 *     $t->softDeletesWithUndo();  // adds deleted_at + restored_at
 *     $t->timestamps();
 * });
 * ```
 */
final class SoftDeletesWithUndoMacro
{
    public static function register(): void
    {
        if (Blueprint::hasMacro('softDeletesWithUndo')) {
            return;
        }

        Blueprint::macro('softDeletesWithUndo', function (
            string $deletedColumn = 'deleted_at',
            string $restoredColumn = 'restored_at',
            int $precision = 0,
        ): Blueprint {
            /** @var Blueprint $this */
            $this->softDeletes($deletedColumn, $precision);
            $this->timestamp($restoredColumn, $precision)->nullable()->index();

            return $this;
        });
    }
}
