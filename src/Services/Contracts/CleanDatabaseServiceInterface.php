<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Services\Contracts;

use Simtabi\Laranail\DbTools\Exceptions\CleanDatabaseException;
use Simtabi\Laranail\DbTools\Services\CleanDatabaseResult;

/**
 * Truncate tables safely: foreign keys handled, protected tables honoured.
 */
interface CleanDatabaseServiceInterface
{
    /**
     * Truncate exactly the named tables.
     *
     * A protected table named here is refused rather than skipped — silently
     * ignoring part of an explicit list would leave the caller believing it ran.
     *
     * @param  list<string>  $tables
     *
     * @throws CleanDatabaseException when the list is empty, names an unknown
     *                                table, or names a protected one
     */
    public function truncate(array $tables, ?string $connection = null): CleanDatabaseResult;

    /**
     * Truncate every table on the connection except the protected ones and
     * anything in `$except`.
     *
     * Protected tables are skipped here, not refused: a whole-database clean
     * asks for "everything reasonable", not for a specific table.
     *
     * @param  list<string>  $except
     */
    public function truncateAll(array $except = [], ?string $connection = null): CleanDatabaseResult;

    /**
     * Tables that will never be truncated by `truncateAll()`.
     *
     * @return list<string>
     */
    public function protectedTables(): array;

    /**
     * Whether a table is on the protected list.
     */
    public function isProtected(string $table): bool;
}
