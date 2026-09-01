<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Backup\Drivers;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use Simtabi\Laranail\DbTools\Backup\Concerns\ResolvesBackupOptions;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupDriverInterface;

/**
 * Class MysqlBackupDriver
 *
 * Handles MySQL/MariaDB database backups using mysqldump, and restores by
 * replaying the dump through the mysql client. Uses secure credential passing
 * via environment variables; all arguments are passed as an array so there is
 * no shell-string interpolation.
 */
class MysqlBackupDriver implements BackupDriverInterface
{
    use ResolvesBackupOptions;

    /**
     * Create a MySQL backup using mysqldump
     *
     * @param  array  $config  Database connection configuration
     * @param  string  $path  Absolute path where backup should be saved
     * @return bool True if backup successful
     *
     * @throws RuntimeException If backup fails
     */
    public function backup(array $config, string $path): bool
    {
        $this->validateConfig($config);

        // When gzipping, mysqldump writes a plain file first which we then
        // compress to the requested ".gz" path.
        $gzip = $this->gzipEnabled();
        $dumpPath = $gzip ? $this->stripGzSuffix($path) : $path;

        $command = [
            $this->binary('mysqldump'),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? '3306'),
            '--user='.$config['username'],
        ];

        foreach ($this->excludedTables() as $table) {
            $command[] = '--ignore-table='.$config['database'].'.'.$table;
        }

        $command[] = $config['database'];
        $command[] = '--result-file='.$dumpPath;

        if (! empty($config['socket'])) {
            $command[] = '--socket='.$config['socket'];
        }

        // Execute with password in environment variable (secure). The env
        // must be set on the pending process *before* run() executes it.
        $result = Process::env(['MYSQL_PWD' => $config['password']])->run($command);

        if (! $result->successful()) {
            throw new RuntimeException('MySQL backup failed: '.$result->errorOutput());
        }

        if ($gzip) {
            $this->gzipCompressFile($dumpPath, $path);
        }

        return true;
    }

    /**
     * Restore a MySQL backup by replaying the dump through the mysql client.
     *
     * @param  array  $config  Database connection configuration
     * @param  string  $path  Absolute path to the dump (".sql" or ".sql.gz")
     * @param  string|null  $connection  Connection name (unused; kept for the contract)
     * @return bool True if restore successful
     *
     * @throws RuntimeException If restore fails
     */
    public function restore(array $config, string $path, ?string $connection = null): bool
    {
        $this->validateConfig($config);

        if (! is_file($path)) {
            throw new RuntimeException("MySQL backup file not found: {$path}");
        }

        // Decompress gzipped dumps to a temp file we feed to the client.
        $sqlPath = $path;
        $tempPath = null;

        if ($this->isGzipPath($path)) {
            $tempPath = $this->makeTempFile('.sql');
            $this->gzipDecompressFile($path, $tempPath);
            $sqlPath = $tempPath;
        }

        $command = [
            $this->binary('mysql'),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? '3306'),
            '--user='.$config['username'],
            $config['database'],
        ];

        if (! empty($config['socket'])) {
            $command[] = '--socket='.$config['socket'];
        }

        try {
            $contents = file_get_contents($sqlPath);

            if ($contents === false) {
                throw new RuntimeException("Unable to read MySQL backup file: {$sqlPath}");
            }

            $result = Process::env(['MYSQL_PWD' => $config['password']])
                ->input($contents)
                ->run($command);

            if (! $result->successful()) {
                throw new RuntimeException('MySQL restore failed: '.$result->errorOutput());
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
     * @param  string  $driver  Driver name
     * @return bool True if driver is mysql or mariadb
     */
    public function supports(string $driver): bool
    {
        return in_array($driver, ['mysql', 'mariadb'], true);
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
                throw new RuntimeException("MySQL backup requires '{$field}' configuration");
            }
        }
    }

    /**
     * Drop a trailing ".gz" so the uncompressed dump is written alongside it.
     */
    private function stripGzSuffix(string $path): string
    {
        return $this->isGzipPath($path) ? substr($path, 0, -3) : $path.'.sql';
    }
}
