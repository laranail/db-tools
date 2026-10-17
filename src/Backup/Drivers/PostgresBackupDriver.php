<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Backup\Drivers;

use Throwable;
use RuntimeException;
use Illuminate\Support\Facades\Process;
use Simtabi\Laranail\DbTools\Backup\Concerns\ResolvesBackupOptions;
use Simtabi\Laranail\DbTools\Backup\Support\BackupConnectionConfig;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupDriverInterface;

/**
 * Class PostgresBackupDriver
 *
 * Handles PostgreSQL database backups using pg_dump (custom format) and
 * restores via pg_restore. Plain ".sql" dumps are replayed through psql.
 * Uses secure credential passing via environment variables; all arguments are
 * passed as an array so there is no shell-string interpolation.
 *
 * TLS reaches libpq through PGSSLMODE/PGSSLROOTCERT/PGSSLCERT/PGSSLKEY, from
 * the same `sslmode`/`sslrootcert`/`sslcert`/`sslkey` keys Laravel puts in the
 * PDO DSN. Archives carry no owners or grants, so a non-superuser on a managed
 * cluster can restore them, and a client older than the server is refused up
 * front: pg_dump cannot dump a newer server, and pg_restore cannot read the
 * archive a newer pg_dump wrote.
 */
class PostgresBackupDriver implements BackupDriverInterface
{
    use ResolvesBackupOptions;

    /**
     * Create a PostgreSQL backup using pg_dump (custom format)
     *
     * @param array $config Database connection configuration
     * @param string $path Absolute path where backup should be saved
     *
     * @return bool True if backup successful
     *
     * @throws RuntimeException If backup fails
     */
    public function backup(array $config, string $path): bool
    {
        $config = BackupConnectionConfig::resolve($config);
        $this->validateConfig($config);
        $this->assertClientIsNotOlderThanServer('pg_dump', $config);

        $gzip = $this->gzipEnabled();
        $dumpPath = $gzip ? $this->stripGzSuffix($path) : $path;

        $command = [
            $this->binary('pg_dump'),
            '--host=' . ($config['host'] ?? '127.0.0.1'),
            '--port=' . ($config['port'] ?? '5432'),
            '--username=' . $config['username'],
            '--dbname=' . $config['database'],
            '--file=' . $dumpPath,
            '--format=custom',
            '--no-password',
            // Ownership and grants name roles that may not exist where the
            // archive is restored, and only a superuser may assign them.
            '--no-owner',
            '--no-privileges',
        ];

        if (! empty($config['schema'])) {
            $command[] = '--schema=' . $config['schema'];
        }

        foreach ($this->excludedTables() as $table) {
            $command[] = '--exclude-table=' . $table;
        }

        // Execute with password in environment variable (secure). The env
        // must be set on the pending process *before* run() executes it.
        $result = Process::env($this->environment($config))->run($command);

        if (! $result->successful()) {
            throw new RuntimeException('PostgreSQL backup failed: ' . $result->errorOutput());
        }

        if ($gzip) {
            $this->gzipCompressFile($dumpPath, $path);
        }

        return true;
    }

    /**
     * Restore a PostgreSQL backup.
     *
     * Custom-format dumps (produced by backup() above, "*.dump"/non-".sql")
     * are restored with pg_restore; plain ".sql" text dumps are replayed with
     * psql. Gzipped files are decompressed to a temp file first.
     *
     * @param array $config Database connection configuration
     * @param string $path Absolute path to the dump
     * @param string|null $connection Connection name (unused; kept for the contract)
     *
     * @return bool True if restore successful
     *
     * @throws RuntimeException If restore fails
     */
    public function restore(array $config, string $path, ?string $connection = null): bool
    {
        $config = BackupConnectionConfig::resolve($config);
        $this->validateConfig($config);

        if (! is_file($path)) {
            throw new RuntimeException("PostgreSQL backup file not found: {$path}");
        }

        $restorePath = $path;
        $tempPath = null;

        if ($this->isGzipPath($path)) {
            $tempPath = $this->makeTempFile();
            $this->gzipDecompressFile($path, $tempPath);
            $restorePath = $tempPath;
            // Strip the ".gz" when deciding the underlying format.
            $path = substr($path, 0, -3);
        }

        try {
            if ($this->isPlainSqlPath($path)) {
                $command = $this->psqlCommand($config, $restorePath);
            } else {
                $this->assertClientIsNotOlderThanServer('pg_restore', $config);
                $command = $this->pgRestoreCommand($config, $restorePath);
            }

            $result = Process::env($this->environment($config))->run($command);

            if (! $result->successful()) {
                throw new RuntimeException('PostgreSQL restore failed: ' . $result->errorOutput());
            }
        } finally {
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }

        return true;
    }

    /**
     * Check if this driver supports the given database driver
     *
     * @param string $driver Driver name
     *
     * @return bool True if driver is pgsql
     */
    public function supports(string $driver): bool
    {
        return $driver === 'pgsql';
    }

    /**
     * Build the pg_restore command for a custom-format dump.
     *
     * @return array<int, string>
     */
    private function pgRestoreCommand(array $config, string $path): array
    {
        $command = [
            $this->binary('pg_restore'),
            '--host=' . ($config['host'] ?? '127.0.0.1'),
            '--port=' . ($config['port'] ?? '5432'),
            '--username=' . $config['username'],
            '--dbname=' . $config['database'],
            '--no-password',
            '--clean',
            '--if-exists',
            // Restore as the connecting user, without the source's grants.
            '--no-owner',
            '--no-privileges',
            // All or nothing: a failure part-way rolls back instead of leaving
            // a half-restored database behind a success.
            '--single-transaction',
            '--exit-on-error',
        ];

        $command[] = $path;

        return $command;
    }

    /**
     * Build the psql command for a plain ".sql" dump.
     *
     * @return array<int, string>
     */
    private function psqlCommand(array $config, string $path): array
    {
        return [
            $this->binary('psql'),
            '--host=' . ($config['host'] ?? '127.0.0.1'),
            '--port=' . ($config['port'] ?? '5432'),
            '--username=' . $config['username'],
            '--dbname=' . $config['database'],
            '--no-password',
            '--file=' . $path,
        ];
    }

    /**
     * libpq environment: the password, and TLS from the connection config.
     *
     * @return array<string, string>
     */
    private function environment(array $config): array
    {
        $environment = ['PGPASSWORD' => (string) $config['password']];

        foreach (['sslmode' => 'PGSSLMODE', 'sslrootcert' => 'PGSSLROOTCERT', 'sslcert' => 'PGSSLCERT', 'sslkey' => 'PGSSLKEY'] as $key => $variable) {
            $value = $config[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $environment[$variable] = $value;
            }
        }

        return $environment;
    }

    /**
     * Refuse to run a client tool older than the server it would talk to.
     *
     * Either version being unknown skips the check: the tool itself then runs
     * and reports whatever stopped the probe (no psql, no route, bad password).
     *
     * @throws RuntimeException If the client's major version is older
     */
    private function assertClientIsNotOlderThanServer(string $tool, array $config): void
    {
        $client = $this->clientMajorVersion($this->binary($tool));

        if ($client === null) {
            return;
        }

        $server = $this->serverMajorVersion($config);

        if ($server === null || $client >= $server) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%1$s %2$d is older than the PostgreSQL %3$d server at %4$s, and cannot %5$s it. '
            . 'Install the PostgreSQL %3$d (or newer) client tools, or point '
            . 'laranail.db-tools.backup.binaries.%1$s at one.',
            $tool,
            $client,
            $server,
            $config['host'] ?? '127.0.0.1',
            $tool === 'pg_dump' ? 'dump' : 'restore into',
        ));
    }

    private function clientMajorVersion(string $binary): ?int
    {
        try {
            $result = Process::run([$binary, '--version']);
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful() || preg_match('/PostgreSQL\)?\s+(\d+)/', $result->output(), $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    private function serverMajorVersion(array $config): ?int
    {
        $command = [
            $this->binary('psql'),
            '--host=' . ($config['host'] ?? '127.0.0.1'),
            '--port=' . ($config['port'] ?? '5432'),
            '--username=' . $config['username'],
            '--dbname=' . $config['database'],
            '--no-password',
            '--no-psqlrc',
            '--tuples-only',
            '--no-align',
            '--command=SHOW server_version_num',
        ];

        try {
            $result = Process::env($this->environment($config))->run($command);
        } catch (Throwable) {
            return null;
        }

        $number = trim($result->output());

        if (! $result->successful() || ! ctype_digit($number)) {
            return null;
        }

        // server_version_num is MMmmpp from PostgreSQL 10 on, and 9.6 is 90600.
        return intdiv((int) $number, 10000);
    }

    /**
     * Whether the path is a plain ".sql" text dump (vs. a custom-format dump).
     */
    private function isPlainSqlPath(string $path): bool
    {
        return str_ends_with(strtolower($path), '.sql');
    }

    /**
     * Validate configuration has required fields
     *
     * @throws RuntimeException If configuration is invalid
     */
    private function validateConfig(array $config): void
    {
        $required = ['host', 'username', 'password', 'database'];

        foreach ($required as $field) {
            if (empty($config[$field])) {
                throw new RuntimeException("PostgreSQL backup requires '{$field}' configuration");
            }
        }
    }

    /**
     * Drop a trailing ".gz" so the uncompressed dump is written alongside it.
     */
    private function stripGzSuffix(string $path): string
    {
        return $this->isGzipPath($path) ? substr($path, 0, -3) : $path;
    }
}
