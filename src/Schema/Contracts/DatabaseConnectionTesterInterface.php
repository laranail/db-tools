<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema\Contracts;

/**
 * Interface DatabaseConnectionTesterInterface
 *
 * Defines contract for testing database connections.
 */
interface DatabaseConnectionTesterInterface
{
    /**
     * Test if a database connection is working
     *
     * @param  string|null  $connection  Connection name (null for default)
     * @return bool True if connection successful
     */
    public function test(?string $connection = null): bool;

    /**
     * Boot-safe availability probe: like test(), but bounded by a short connect
     * timeout so an unreachable or blackholed host fails fast instead of blocking
     * for the driver's default connect timeout. Never mutates the real connection
     * and never throws.
     *
     * @param  string|null  $connection  Connection name (null for default)
     * @param  int|null  $timeout  Connect timeout in seconds (null = config default)
     * @return bool True if a connection could be opened within the timeout
     */
    public function probe(?string $connection = null, ?int $timeout = null): bool;

    /**
     * Test connection and return detailed information
     *
     * @param  string|null  $connection  Connection name (null for default)
     * @return array Connection details
     */
    public function testDetailed(?string $connection = null): array;

    /**
     * Get the database driver name
     *
     * @param  string|null  $connection  Connection name (null for default)
     * @return string Driver name
     */
    public function getDriver(?string $connection = null): string;

    /**
     * Get the database server version
     *
     * @param  string|null  $connection  Connection name (null for default)
     * @return string|null Version string
     */
    public function getVersion(?string $connection = null): ?string;

    /**
     * Get the database name
     *
     * @param  string|null  $connection  Connection name (null for default)
     * @return string|null Database name
     */
    public function getDatabaseName(?string $connection = null): ?string;
}
