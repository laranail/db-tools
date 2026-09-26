<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

use Closure;
use Throwable;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;

/**
 * Run a bulk write with a table's row triggers suspended, then restore them.
 *
 * A per-row trigger that maintains derived data (a search column, a counter) is
 * the right design for single writes and the slowest possible one for a bulk
 * load of thousands of rows. Suspending it and rebuilding the derived data once
 * afterwards is the usual answer — but only PostgreSQL can suspend a trigger
 * (`ALTER TABLE … DISABLE TRIGGER`), and only a table owner may.
 *
 * So the callback is told whether suspension actually happened, and the caller
 * decides what that means:
 *
 *     DbTools::withoutTriggers('icons', ['trg_icons_search'], function (bool $suspended) use ($rows) {
 *         Icon::upsert($rows, ['path'], ['svg']);
 *
 *         if ($suspended) {
 *             // the trigger did not run: rebuild the search column for these rows
 *         }
 *     });
 *
 * On any other driver, or when disabling fails (insufficient privilege, unknown
 * trigger), the callback runs with `false` and the triggers are left as they
 * were. Every trigger that was disabled is re-enabled in `finally`, even when
 * the callback throws.
 */
final class TriggerSuspender
{
    /**
     * @template TReturn
     *
     * @param list<string> $triggers
     * @param Closure(bool): TReturn $callback receives whether the triggers are suspended
     *
     * @return TReturn
     */
    public static function run(string $table, array $triggers, Closure $callback, ?string $connection = null): mixed
    {
        $db = ConnectionContext::for($connection)->connection();

        if ($db->getDriverName() !== 'pgsql' || $triggers === []) {
            return $callback(false);
        }

        $grammar = $db->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($table);
        $disabled = [];

        try {
            foreach ($triggers as $trigger) {
                $db->statement("ALTER TABLE {$wrappedTable} DISABLE TRIGGER " . $grammar->wrap($trigger));
                $disabled[] = $trigger;
            }
        } catch (Throwable) {
            // Could not suspend all of them: restore what was suspended and run
            // with the triggers live, which is slower but correct.
            self::enable($table, $disabled, $connection);

            return $callback(false);
        }

        try {
            return $callback(true);
        } finally {
            self::enable($table, $disabled, $connection);
        }
    }

    /**
     * @param list<string> $triggers
     */
    private static function enable(string $table, array $triggers, ?string $connection): void
    {
        $db = ConnectionContext::for($connection)->connection();
        $grammar = $db->getQueryGrammar();

        foreach ($triggers as $trigger) {
            $db->statement('ALTER TABLE ' . $grammar->wrapTable($table) . ' ENABLE TRIGGER ' . $grammar->wrap($trigger));
        }
    }
}
