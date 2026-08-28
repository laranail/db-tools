<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Backup\Drivers;

use RuntimeException;
use Illuminate\Support\Facades\Process;
use Simtabi\Laranail\DbTools\Backup\Concerns\ResolvesBackupOptions;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupDriverInterface;

/**
 * Class PostgresBackupDriver
 *
 * Handles PostgreSQL database backups using pg_dump (custom format) and
 * restores via pg_restore. Plain ".sql" dumps are replayed through psql.
 * Uses secure credential passing via environment variables; all arguments are
 * passed as an array so there is no shell-string interpolation.
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
        $this->validateConfig($config);

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
        ];

        if (! empty($config['schema'])) {
            $command[] = '--schema=' . $config['schema'];
        }

        foreach ($this->excludedTables() as $table) {
            $command[] = '--exclude-table=' . $table;
        }

        // Execute with password in environment variable (secure). The env
        // must be set on the pending process *before* run() executes it.
        $result = Process::env(['PGPASSWORD' => $config['password']])->run($command);

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
            $command = $this->isPlainSqlPath($path)
                ? $this->psqlCommand($config, $restorePath)
                : $this->pgRestoreCommand($config, $restorePath);

            $result = Process::env(['PGPASSWORD' => $config['password']])->run($command);

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
