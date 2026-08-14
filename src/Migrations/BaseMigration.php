<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Builder;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;

/**
 * Shared behaviour for schema migrations that own a group of related tables.
 *
 * Declaring {@see tables()} gives `down()` for free and, more usefully, states
 * the creation order in one place — which is also the order the foreign keys
 * depend on, so getting it right once is worth more than the method saves.
 *
 * ## Deliberately nothing else, in particular no column helpers
 *
 * `$table->foreignId('user_id')->constrained()->cascadeOnDelete()` is the
 * obvious thing to extract and must not be: **larastan derives model property
 * types by parsing migration files for `Schema::create` calls.** A column added
 * from a helper is invisible to that parse, and every model gains an "access to
 * an undefined property" error for it. Repeating one readable line costs less
 * than the abstraction does.
 */
abstract class BaseMigration extends Migration
{
    /**
     * The tables this migration owns, in creation order.
     *
     * @return list<string>
     */
    abstract protected function tables(): array;

    /**
     * Drop in reverse creation order, so dependent tables go before their
     * parents and the foreign keys resolve.
     *
     * Gated by {@see ReversalPolicy}: dropping these tables deletes every row
     * they hold, and `migrate:rollback` asks for no confirmation.
     */
    public function down(): void
    {
        ReversalPolicy::guard();

        $schema = $this->schema();

        foreach (array_reverse($this->tables()) as $table) {
            $schema->dropIfExists($table);
        }
    }

    /**
     * The connection this migration's tables live on.
     *
     * Pinned rather than inherited. `Migration::$connection` is what the
     * framework reads to decide where a migration runs, so reading it here too
     * means `down()` drops from the same place `up()` created — rather than
     * from whatever connection happened to be default when the rollback ran.
     *
     * Routed through {@see ConnectionContext} rather than `Schema::connection()`
     * because this package's architecture test forbids the bare facade: it
     * silently resolves the default connection, and every past instance of that
     * produced a bug — a transaction on the wrong connection, an availability
     * memo that forked. The seam is the one place the question is answered, and
     * a migration base class is not the exception that proves it.
     */
    protected function schema(): Builder
    {
        return ConnectionContext::for($this->getConnection())->schema();
    }
}
