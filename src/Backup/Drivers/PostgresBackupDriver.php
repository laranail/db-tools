<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Backup\Drivers;

use Throwable;
use RuntimeException;
use Illuminate\Support\Facades\Process;
use Simtabi\Laranail\DbTools\Backup\Enums\PreflightStatus;
use Simtabi\Laranail\DbTools\Backup\Support\BackupPreflight;
use Simtabi\Laranail\DbTools\Backup\Concerns\ResolvesBackupOptions;
use Simtabi\Laranail\DbTools\Backup\Support\BackupConnectionConfig;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupDriverInterface;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupDriverPreflightInterface;

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
 * PDO DSN, and `hostaddr` reaches it as PGHOSTADDR, so a server reached
 * through a tunnel is still verified against its real name. Restores leave
 * owners and grants out (--no-owner --no-privileges), so a non-superuser on
 * a managed cluster can restore; archives keep them. A pg_dump older than the
 * server is refused up front, since it cannot dump a newer server, and a
 * pg_restore older than the archive's pg_dump is reported as such. The
 * password still travels in PGPASSWORD.
 */
class PostgresBackupDriver implements BackupDriverInterface, BackupDriverPreflightInterface
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
            // Owners and grants stay in the archive, so a restore with another
            // tool keeps them; this driver's restore leaves them out instead.
        ];

        if (! empty($config['schema'])) {
            $command[] = '--schema=' . $config['schema'];
        }

        foreach ($this->excludedTables() as $table) {
            $command[] = '--exclude-table=' . $table;
        }

        // Execute with password in environment variable (secure). The env
        // must be set on the pending process *before* run() executes it.
        $result = $this->backupProcess()->env($this->environment($config))->run($command);

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
                // pg_restore's limit is the archive, not the server: it reads
                // any archive an equal or older pg_dump wrote, into any server.
                $command = $this->pgRestoreCommand($config, $restorePath);
            }

            $result = $this->backupProcess()->env($this->environment($config))->run($command);

            if (! $result->successful()) {
                $error = $result->errorOutput();

                if (str_contains($error, 'unsupported version') && str_contains($error, 'file header')) {
                    throw new RuntimeException(
                        'PostgreSQL restore failed: the archive was written by a newer pg_dump than this pg_restore. '
                        . 'Install newer PostgreSQL client tools, or point laranail.db-tools.backup.binaries.pg_restore '
                        . 'at one. ' . $error,
                    );
                }

                throw new RuntimeException('PostgreSQL restore failed: ' . $error);
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
     * Whether pg_dump could back this connection up now: it runs, and it is
     * not older than the server. Nothing is dumped.
     *
     * @throws RuntimeException If the configuration is invalid
     */
    public function preflight(array $config): BackupPreflight
    {
        $config = BackupConnectionConfig::resolve($config);
        $this->validateConfig($config);

        return $this->inspectClient('pg_dump', $config);
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
            // psql carries on past a failed statement and still exits 0, so a
            // half-applied restore used to be reported as a success.
            '--set=ON_ERROR_STOP=1',
            '--file=' . $path,
        ];
    }

    /**
     * libpq environment: the password, the address to dial when it differs
     * from the name to verify (hostaddr), and TLS from the connection config.
     *
     * @return array<string, string>
     */
    private function environment(array $config): array
    {
        $environment = ['PGPASSWORD' => (string) $config['password']];

        foreach (['hostaddr' => 'PGHOSTADDR', 'sslmode' => 'PGSSLMODE', 'sslrootcert' => 'PGSSLROOTCERT', 'sslcert' => 'PGSSLCERT', 'sslkey' => 'PGSSLKEY'] as $key => $variable) {
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
     * Either version being unknown skips the check, and so does a tool that
     * will not run: the tool itself then runs and reports whatever stopped
     * the probe (no psql, no route, bad password, not installed).
     *
     * @throws RuntimeException If the client's major version is older
     */
    private function assertClientIsNotOlderThanServer(string $tool, array $config): void
    {
        $preflight = $this->inspectClient($tool, $config);

        if ($preflight->status === PreflightStatus::Outdated) {
            throw new RuntimeException($preflight->message);
        }
    }

    /**
     * @param array<string, mixed> $config an already resolved, validated config
     */
    private function inspectClient(string $tool, array $config): BackupPreflight
    {
        $binary = $this->binary($tool);
        $probe = $this->clientMajorVersion($binary);

        if ($probe === false) {
            return BackupPreflight::missing($tool, $binary);
        }

        $client = $probe;
        $server = $client === null ? null : $this->serverMajorVersion($config);
        $host = $config['host'] ?? '127.0.0.1';

        if ($client !== null && $server !== null && $client < $server) {
            return new BackupPreflight(
                PreflightStatus::Outdated,
                $tool,
                $binary,
                $client,
                $server,
                sprintf(
                    '%1$s %2$d is older than the PostgreSQL %3$d server at %4$s, and cannot %5$s it. '
                    . 'Install the PostgreSQL %3$d (or newer) client tools, or point '
                    . 'laranail.db-tools.backup.binaries.%1$s at one.',
                    $tool,
                    $client,
                    $server,
                    $host,
                    'dump',
                ),
            );
        }

        return BackupPreflight::ready($tool, $binary, $client, $server, match (true) {
            $client === null => "{$tool} runs, but its version could not be read.",
            $server === null => "{$tool} {$client} runs; the server's version could not be read, so it was not compared.",
            default          => "{$tool} {$client} can dump the PostgreSQL {$server} server at {$host}.",
        });
    }

    /**
     * The client's major version; null when it runs but prints no version
     * this can read, false when it does not run at all.
     */
    private function clientMajorVersion(string $binary): int|false|null
    {
        try {
            $result = Process::run([$binary, '--version']);
        } catch (Throwable) {
            return false;
        }

        if (! $result->successful()) {
            return false;
        }

        if (preg_match('/PostgreSQL\)?\s+(\d+)/', $result->output(), $m) !== 1) {
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
            // A probe, not the job: an unreachable host must not stall the
            // backup for Laravel's 60 seconds before pg_dump times out itself.
            $result = Process::timeout(30)
                ->env([...$this->environment($config), 'PGCONNECT_TIMEOUT' => '10'])
                ->run($command);
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
