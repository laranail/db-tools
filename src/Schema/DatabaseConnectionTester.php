<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

use Exception;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDOException;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseConnectionTesterInterface;

/**
 * Class DatabaseConnectionTester
 *
 * Tests database connections and retrieves connection information.
 * Supports MySQL, PostgreSQL, SQLite, and SQL Server.
 */
class DatabaseConnectionTester implements DatabaseConnectionTesterInterface
{
    /**
     * Test if a database connection is working
     *
     * @param  string|null  $connection  Connection name (null for default)
     * @return bool True if connection successful
     */
    public function test(?string $connection = null): bool
    {
        try {
            $conn = $this->getConnection($connection);
            $conn->getPdo();

            return true;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Test connection and return detailed information
     *
     * @param  string|null  $connection  Connection name (null for default)
     * @return array Connection details
     */
    public function testDetailed(?string $connection = null): array
    {
        try {
            $conn = $this->getConnection($connection);
            $conn->getPdo();

            return [
                'success' => true,
                'message' => 'Connection successful',
                'connection' => $connection ?? config('database.default'),
                'driver' => $conn->getDriverName(),
                'version' => $this->getVersion($connection),
                'database' => $conn->getDatabaseName(),
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Database error: '.$e->getMessage(),
                'connection' => $connection ?? config('database.default'),
            ];
        } catch (InvalidArgumentException $e) {
            return [
                'success' => false,
                'message' => 'Configuration error: '.$e->getMessage(),
                'connection' => $connection ?? config('database.default'),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
                'connection' => $connection ?? config('database.default'),
            ];
        }
    }

    /**
     * Get the database driver name
     *
     * @param  string|null  $connection  Connection name (null for default)
     * @return string Driver name
     */
    public function getDriver(?string $connection = null): string
    {
        try {
            return $this->getConnection($connection)->getDriverName();
        } catch (Exception) {
            return 'unknown';
        }
    }

    /**
     * Get the database server version
     *
     * @param  string|null  $connection  Connection name (null for default)
     * @return string|null Version string
     */
    public function getVersion(?string $connection = null): ?string
    {
        try {
            $conn = $this->getConnection($connection);
            $driver = $conn->getDriverName();

            $query = match ($driver) {
                'mysql', 'mariadb' => 'SELECT VERSION() as version',
                'pgsql' => 'SELECT version() as version',
                'sqlite' => 'SELECT sqlite_version() as version',
                'sqlsrv' => 'SELECT @@VERSION as version',
                default => null,
            };

            if (! $query) {
                return null;
            }

            $result = $conn->selectOne($query);

            return $this->normalizeVersion($result);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Normalize a version result that may come back as a scalar, an array, or
     * an object depending on the driver (e.g. SQLite's sqlite_version()
     * returns a scalar where MySQL/PostgreSQL return a "version" column).
     */
    private function normalizeVersion(mixed $result): ?string
    {
        if ($result === null) {
            return null;
        }

        if (is_scalar($result)) {
            return (string) $result;
        }

        $value = match (true) {
            is_array($result) => $result['version'] ?? $result[0] ?? reset($result),
            is_object($result) => $result->version ?? null,
            default => null,
        };

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Get the database name
     *
     * @param  string|null  $connection  Connection name (null for default)
     * @return string|null Database name
     */
    public function getDatabaseName(?string $connection = null): ?string
    {
        try {
            return $this->getConnection($connection)->getDatabaseName();
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Get a database connection instance
     *
     * @param  string|null  $connection  Connection name (null for default)
     */
    protected function getConnection(?string $connection = null): Connection
    {
        return $connection ? DB::connection($connection) : DB::connection();
    }
}
