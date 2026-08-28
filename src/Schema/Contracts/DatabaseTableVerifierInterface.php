<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema\Contracts;

/**
 * Interface DatabaseTableVerifierInterface
 *
 * Defines contract for verifying database tables.
 */
interface DatabaseTableVerifierInterface
{
    /**
     * Verify tables exist in the database
     *
     * @param array $tables List of table names to check
     * @param bool $requireAll If true, all tables must exist
     * @param string|null $connection Connection name (null for default)
     *
     * @return bool True if verification passes
     */
    public function verify(array $tables, bool $requireAll = false, ?string $connection = null): bool;

    /**
     * Verify tables exist and return detailed information
     *
     * @param array $tables List of table names to check
     * @param bool $requireAll If true, all tables must exist
     * @param bool $testConnection Whether to test connection first
     * @param string|null $connection Connection name (null for default)
     *
     * @return array Detailed verification results
     */
    public function verifyDetailed(
        array $tables,
        bool $requireAll = false,
        bool $testConnection = true,
        ?string $connection = null,
    ): array;

    /**
     * Get list of tables that exist from the provided list
     *
     * @param array $tables Tables to check
     * @param string|null $connection Connection name (null for default)
     *
     * @return array List of existing tables
     */
    public function getExistingTables(array $tables, ?string $connection = null): array;

    /**
     * Get list of tables that don't exist from the provided list
     *
     * @param array $tables Tables to check
     * @param string|null $connection Connection name (null for default)
     *
     * @return array List of missing tables
     */
    public function getMissingTables(array $tables, ?string $connection = null): array;

    /**
     * Quick check if essential Laravel tables exist
     *
     * @param bool $strict Whether all default tables must exist
     * @param string|null $connection Connection name (null for default)
     *
     * @return bool True if tables exist
     */
    public function hasLaravelTables(bool $strict = false, ?string $connection = null): bool;
}
