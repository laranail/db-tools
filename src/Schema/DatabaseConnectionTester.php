<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

use Exception;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
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
     * Reserved name for the throwaway connection used by {@see probe()}.
     */
    private const string PROBE_CONNECTION = '__db_tools_probe__';

    public function probe(?string $connection = null, ?int $timeout = null): bool
    {
        $name = $connection ?? (string) config('database.default');

        // Fast path: if the connection already has a live PDO (the app has
        // queried it this request), it is trivially available — no need to open
        // a throwaway probe connection. getRawPdo() is a Closure until the
        // connection is actually opened, so only a real PDO counts.
        try {
            if ($this->getConnection($connection)->getRawPdo() instanceof PDO) {
                return true;
            }
        } catch (Exception) {
            // Fall through to the bounded probe below.
        }

        $config = config("database.connections.{$name}");

        // Unknown/undefined connection: nothing to clone, so bound the real one
        // as best we can and fall back to a plain test.
        if (! is_array($config)) {
            return $this->test($connection);
        }

        $timeout ??= (int) config('db-tools.guard.probe_timeout', 2);
        $timeout = max(1, $timeout);

        Config::set(
            'database.connections.'.self::PROBE_CONNECTION,
            $this->withConnectTimeout($config, $timeout),
        );

        try {
            DB::connection(self::PROBE_CONNECTION)->getPdo();

            return true;
        } catch (Exception) {
            return false;
        } finally {
            DB::purge(self::PROBE_CONNECTION);

            $connections = (array) config('database.connections', []);
            unset($connections[self::PROBE_CONNECTION]);
            Config::set('database.connections', $connections);
        }
    }

    /**
     * Overlay a short connect timeout onto a connection config, per driver.
     * mysql/mariadb/sqlsrv honour PDO::ATTR_TIMEOUT as the connect timeout;
     * pgsql reads a connect_timeout DSN param; sqlite is always local (no-op).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withConnectTimeout(array $config, int $timeout): array
    {
        $driver = $config['driver'] ?? null;
        $options = $config['options'] ?? [];

        if (in_array($driver, ['mysql', 'mariadb', 'sqlsrv'], true)) {
            $options[PDO::ATTR_TIMEOUT] = $timeout;
        }

        if ($driver === 'pgsql') {
            // Best-effort across Laravel versions: DSN param + attr.
            $config['connect_timeout'] = $timeout;
            $options[PDO::ATTR_TIMEOUT] = $timeout;
        }

        $config['options'] = $options;

        return $config;
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
