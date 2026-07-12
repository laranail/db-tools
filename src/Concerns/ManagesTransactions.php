<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

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
     * @param  Closure(): TReturn  $callback  The callback to execute
     * @param  int  $attempts  Number of attempts if deadlock occurs
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
    protected function transaction(Closure $callback, int $attempts = 1): mixed
    {
        return DB::transaction($callback, $attempts);
    }

    /**
     * Execute a callback within a manually managed transaction
     *
     * Provides explicit control over transaction lifecycle. Useful when you need
     * to perform operations before commit or handle errors specially.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback  The callback to execute
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
    protected function transactionOrFail(Closure $callback): mixed
    {
        DB::beginTransaction();

        try {
            $result = $callback();
            DB::commit();

            return $result;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Check if currently in a transaction
     */
    protected function inTransaction(): bool
    {
        return DB::transactionLevel() > 0;
    }

    /**
     * Get the current transaction nesting level
     */
    protected function getTransactionLevel(): int
    {
        return DB::transactionLevel();
    }
}
