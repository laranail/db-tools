<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Migrations;

use Illuminate\Console\Events\CommandStarting;

/**
 * Applies {@see ReversalPolicy} to the commands that drop tables without ever
 * calling `down()`.
 *
 * ## Which commands, and why only these
 *
 * Traced through the framework rather than assumed:
 *
 * | Command | Path | Guarded by `down()`? |
 * |---|---|---|
 * | `migrate:rollback` | `Migrator::rollback()` → `runDown()` | yes |
 * | `migrate:reset` | `Migrator::reset()` → `resetMigrations()` → `runDown()` | **yes** |
 * | `migrate:fresh` | `db:wipe` → `dropAllTables()` | no |
 * | `db:wipe` | `dropAllTables()` | no |
 *
 * `migrate:reset` is on that list because it genuinely does run every `down()`
 * — a plan that put it in the unguarded column was wrong, and guarding it here
 * as well would only produce two refusals for one command.
 *
 * The remaining two go straight to the schema builder, so no migration's
 * `down()` runs and a `BaseMigration` guard cannot see them. This listener is
 * how they get one.
 *
 * ## Why a listener rather than overriding the commands
 *
 * Replacing a framework command means inheriting its implementation and
 * tracking it across releases. `CommandStarting` fires before `handle()` with
 * the resolved name, which is all this needs, and it leaves the commands alone.
 */
final class GuardsDestructiveCommands
{
    /**
     * Commands that drop tables without running any migration's `down()`.
     *
     * @var array<string, string>
     */
    private const array UNGUARDED = [
        'migrate:fresh' => 'drop every table and re-migrate',
        'db:wipe'       => 'drop every table',
    ];

    public function handle(CommandStarting $event): void
    {
        $operation = self::UNGUARDED[$event->command ?? ''] ?? null;

        if ($operation === null) {
            return;
        }

        // --force is the framework's own "yes, in production" for these
        // commands, and honouring it keeps a deliberate deploy working. The
        // policy still refuses without it, which is the case that matters:
        // nobody types --force by accident.
        if ($event->input->hasParameterOption('--force')) {
            return;
        }

        ReversalPolicy::guard($operation);
    }
}
