<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

use Throwable;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;
use Simtabi\Laranail\DbTools\Exceptions\DbToolsException;

/**
 * Turns foreign-key enforcement off and on for one connection -- the single
 * implementation behind DbTools::withoutForeignKeyChecks() and the
 * ManagesForeignKeyChecks trait.
 *
 * MySQL, MariaDB and SQLite can switch foreign keys off outright, and Laravel's
 * schema builder does exactly that. PostgreSQL cannot switch off *only* foreign
 * keys, so there are two honest modes, chosen by
 * `laranail.db-tools.foreign_keys.postgres_mode`:
 *
 * - `defer` (the default) -- Laravel's `SET CONSTRAINTS ALL DEFERRED`. Checks
 *   move to the end of the transaction, and only for constraints declared
 *   DEFERRABLE. An ordinary foreign key is still enforced immediately. Nothing
 *   else about the session changes.
 * - `replica` -- `SET session_replication_role = replica`. Foreign keys really
 *   are off, and so is every ordinary trigger on every table, for as long as the
 *   block runs: derived data a trigger maintains is NOT maintained. Needs a
 *   superuser, or on PostgreSQL 15+ a role granted `SET` on the parameter. A
 *   role without that fails loudly rather than quietly degrading to `defer`.
 */
final class ForeignKeySwitch
{
    public const string DEFER = 'defer';

    public const string REPLICA = 'replica';

    public static function disable(?string $connection = null): void
    {
        $context = ConnectionContext::for($connection);

        if (self::usesReplicaRole($context)) {
            try {
                $context->connection()->statement('SET session_replication_role = replica');
            } catch (Throwable $e) {
                throw new DbToolsException(
                    'PostgreSQL refused `SET session_replication_role = replica`, which '
                    . "laranail.db-tools.foreign_keys.postgres_mode = 'replica' needs. Grant the role "
                    . "SET on session_replication_role (PostgreSQL 15+) or use a superuser, or set the mode back to 'defer'.",
                    previous: $e,
                    context: ['connection' => $context->key()],
                );
            }

            return;
        }

        $context->schema()->disableForeignKeyConstraints();
    }

    public static function enable(?string $connection = null): void
    {
        $context = ConnectionContext::for($connection);

        if (self::usesReplicaRole($context)) {
            $context->connection()->statement('SET session_replication_role = DEFAULT');

            return;
        }

        $context->schema()->enableForeignKeyConstraints();
    }

    /**
     * The configured PostgreSQL mode. An unknown value is a configuration error,
     * not a reason to guess.
     */
    public static function postgresMode(): string
    {
        $mode = config('laranail.db-tools.foreign_keys.postgres_mode', self::DEFER);

        if (! in_array($mode, [self::DEFER, self::REPLICA], true)) {
            throw new DbToolsException(sprintf(
                "laranail.db-tools.foreign_keys.postgres_mode must be '%s' or '%s', got %s.",
                self::DEFER,
                self::REPLICA,
                var_export($mode, true),
            ));
        }

        return $mode;
    }

    private static function usesReplicaRole(ConnectionContext $context): bool
    {
        return $context->connection()->getDriverName() === 'pgsql' && self::postgresMode() === self::REPLICA;
    }
}
