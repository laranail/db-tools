<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Closure;
use Throwable;
use Illuminate\Database\ConnectionInterface;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;

/**
 * Trait ManagesTransactions
 *
 * Provides simplified transaction management methods for database operations.
 * Offers both automatic (Laravel's transaction helper) and manual transaction control.
 */
trait ManagesTransactions
{
    /**
     * Execute a callback within a database transaction
     *
     * Uses Laravel's automatic transaction management with proper rollback on exceptions.
     *
     * @template TReturn
     *
     * @param Closure(): TReturn $callback The callback to execute
     * @param int $attempts Number of attempts if deadlock occurs
     *
     * @return TReturn The callback return value
     *
     * @throws Throwable If transaction fails
     *
     * @example
     * $result = $this->transaction(function() {
     *     $user = User::create([...]);
     *     $profile = Profile::create([...]);
     *     return $user;
     * });
     */
    protected function transaction(Closure $callback, int $attempts = 1, ?string $connection = null): mixed
    {
        return $this->transactionConnection($connection)->transaction($callback, $attempts);
    }

    /**
     * Execute a callback within a manually managed transaction
     *
     * Provides explicit control over transaction lifecycle. Useful when you need
     * to perform operations before commit or handle errors specially.
     *
     * @template TReturn
     *
     * @param Closure(): TReturn $callback The callback to execute
     *
     * @return TReturn The callback return value
     *
     * @throws Throwable If transaction fails (after rollback)
     *
     * @example
     * $result = $this->transactionOrFail(function() {
     *     $user = User::create([...]);
     *     // Do something before commit
     *     return $user;
     * });
     */
    protected function transactionOrFail(Closure $callback, ?string $connection = null): mixed
    {
        $db = $this->transactionConnection($connection);

        $db->beginTransaction();

        try {
            $result = $callback();
            $db->commit();

            return $result;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Check if currently in a transaction
     */
    protected function inTransaction(?string $connection = null): bool
    {
        return $this->getTransactionLevel($connection) > 0;
    }

    /**
     * Get the current transaction nesting level
     */
    protected function getTransactionLevel(?string $connection = null): int
    {
        return $this->transactionConnection($connection)->transactionLevel();
    }

    /**
     * Resolve the connection a transaction should be opened on.
     *
     * Transactions are per-connection. Without this, a caller that opened a
     * transaction here but ran its statements on another connection got no
     * atomicity at all: the work committed as it went, and the rollback undid an
     * empty transaction on the default connection.
     */
    private function transactionConnection(?string $connection): ConnectionInterface
    {
        return ConnectionContext::for($connection)->connection();
    }
}
