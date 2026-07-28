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

    public function probe(?string $connection = null, ?int $timeout = null): bool
    {
        $name = $connection ?? (string) config('database.default');

        $config = config("database.connections.{$name}");

        if (! is_array($config)) {
            return $this->test($connection);
        }

        // SQLite (and any local driver) cannot hang on connect, so just open
        // the real connection and reuse it — no timeout machinery.
        if (($config['driver'] ?? null) === 'sqlite') {
            return $this->test($connection);
        }

        $timeout ??= (int) config('laranail.db-tools.guard.probe_timeout', 2);
        $timeout = max(1, $timeout);

        // Already resolved this request? The PDO exists, so there is nothing to
        // bound — just reuse it. We never purge (that would close a live PDO and
        // wipe a :memory: sqlite database) and never clone: probing opens the
        // real connection once and every later query reuses it, so a healthy
        // check costs a single connection.
        if (array_key_exists($name, $this->resolvedConnections())) {
            return $this->test($connection);
        }

        // Not yet resolved: overlay a bounded connect timeout so a dead host
        // fails fast (~timeout) instead of blocking for the driver's ~30s
        // default. The overlay is restored immediately afterwards — Laravel
        // copies the config when it builds the connection, so the timeout
        // applies to the connection we open here without leaking into the
        // application's configuration. Leaving it in place would silently
        // re-apply on any later rebuild (DB::purge(), a reconnect, a recycled
        // worker) as if the developer had configured it.
        $key = "database.connections.{$name}";

        Config::set($key, $this->withConnectTimeout($config, $timeout));

        try {
            // test() opens the real connection (reused thereafter) and never throws.
            return $this->test($connection);
        } finally {
            Config::set($key, $config);
        }
    }

    /**
     * The database connections already resolved this request, keyed by name.
     *
     * @return array<string, mixed>
     */
    private function resolvedConnections(): array
    {
        try {
            return DB::getConnections();
        } catch (Exception) {
            return [];
        }
    }

    /**
     * Overlay a short CONNECT timeout onto a connection config, per driver.
     *
     * The mapping is deliberately driver-specific because PDO::ATTR_TIMEOUT does
     * not mean the same thing everywhere:
     *
     *   mysql/mariadb  PDO::ATTR_TIMEOUT → MYSQL_OPT_CONNECT_TIMEOUT. Connect-only.
     *   pgsql          `connect_timeout` is appended to the DSN by Laravel's
     *                  PostgresConnector. Connect-only.
     *   sqlsrv         PDO::ATTR_TIMEOUT is the QUERY timeout, not the connect
     *                  timeout — setting it here would cap every later query on
     *                  the connection the probe opens (which is reused) at a
     *                  couple of seconds. Laravel's SqlServerConnector maps
     *                  `login_timeout` to the DSN's LoginTimeout, which is the
     *                  correct connect-only knob.
     *   sqlite         local; never reached (short-circuited in probe()).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withConnectTimeout(array $config, int $timeout): array
    {
        $driver = $config['driver'] ?? null;
        $options = $config['options'] ?? [];

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $options[PDO::ATTR_TIMEOUT] = $timeout;
        }

        if ($driver === 'pgsql') {
            $config['connect_timeout'] = $timeout;
        }

        if ($driver === 'sqlsrv') {
            $config['login_timeout'] = $timeout;
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
