<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Backup\Drivers;

use Generator;
use Throwable;
use RuntimeException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Simtabi\Laranail\DbTools\Backup\Concerns\ResolvesBackupOptions;
use Simtabi\Laranail\DbTools\Backup\Support\BackupConnectionConfig;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupDriverInterface;

/**
 * Class MysqlBackupDriver
 *
 * Handles MySQL/MariaDB database backups using mysqldump, and restores by
 * streaming the dump through the mysql client. The password travels in a
 * private, short-lived option file (never argv or the environment); all
 * arguments are passed as an array so there is no shell-string interpolation.
 *
 * The flags are the ones a managed server needs: a consistent snapshot without
 * table locks, no tablespace or GTID statements the app user cannot run, the
 * routines and triggers the schema depends on, TLS mapped from the same PDO
 * options the application connects with, and DEFINER clauses stripped on the
 * way back in, since only SUPER may name another account as definer.
 */
class MysqlBackupDriver implements BackupDriverInterface
{
    use ResolvesBackupOptions;

    /**
     * PDO MySQL attribute values (Pdo\Mysql::ATTR_SSL_*, PDO::MYSQL_ATTR_SSL_*).
     * Literal so the driver loads where pdo_mysql is not installed, and so the
     * PHP 8.5 rename does not matter: both spellings carry these integers.
     */
    private const int ATTR_SSL_KEY = 1007;

    private const int ATTR_SSL_CERT = 1008;

    private const int ATTR_SSL_CA = 1009;

    private const int ATTR_SSL_CAPATH = 1010;

    private const int ATTR_SSL_CIPHER = 1011;

    private const int ATTR_SSL_VERIFY_SERVER_CERT = 1014;

    /**
     * MySQL client --ssl-mode values, keyed by the spellings accepted for them
     * (MySQL's own, and libpq's, which a DATABASE_URL copied from a provider's
     * Postgres panel tends to carry).
     */
    private const array SSL_MODES = [
        'DISABLED'        => 'DISABLED',
        'DISABLE'         => 'DISABLED',
        'PREFERRED'       => 'PREFERRED',
        'PREFER'          => 'PREFERRED',
        'ALLOW'           => 'PREFERRED',
        'REQUIRED'        => 'REQUIRED',
        'REQUIRE'         => 'REQUIRED',
        'VERIFY_CA'       => 'VERIFY_CA',
        'VERIFY_IDENTITY' => 'VERIFY_IDENTITY',
        'VERIFY_FULL'     => 'VERIFY_IDENTITY',
    ];

    /**
     * `--help` output per binary, so each client is probed once.
     *
     * @var array<string, string>
     */
    private array $clientHelp = [];

    /**
     * Remove a `DEFINER=user@host` clause from one line of a mysqldump file.
     *
     * Row data is left alone: mysqldump writes every INSERT on a single line,
     * so a value that happens to contain the text is never rewritten.
     */
    public static function stripDefiner(string $line): string
    {
        if (preg_match('/^\s*(INSERT|REPLACE)\s/i', $line) === 1) {
            return $line;
        }

        $identifier = '(?:`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\'|[^\s@*`\']+)';

        return (string) preg_replace("/\\bDEFINER\\s*=\\s*{$identifier}\\s*@\\s*{$identifier}/i", '', $line);
    }

    /**
     * Create a MySQL backup using mysqldump
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

        // When gzipping, mysqldump writes a plain file first which we then
        // compress to the requested ".gz" path.
        $gzip = $this->gzipEnabled();
        $dumpPath = $gzip ? $this->stripGzSuffix($path) : $path;
        $binary = $this->binary('mysqldump');
        $defaultsFile = $this->writeDefaultsFile($config);

        try {
            $command = [
                $binary,
                // Must be the first option, or the client ignores it.
                '--defaults-extra-file=' . $defaultsFile,
                ...$this->connectionArguments($config, $binary),
                // A consistent InnoDB snapshot without LOCK TABLES, which a
                // managed app user may not hold.
                '--single-transaction',
                // Tablespace statements need PROCESS; managed users lack it.
                '--no-tablespaces',
                '--triggers',
            ];

            // Stored routines and events restore only where the user may
            // create them: a binlogged managed server refuses a function from
            // a non-SUPER user (1418) unless log_bin_trust_function_creators is
            // on. So they are opt-in, as they are in mysqldump itself.
            if ((bool) Config::get('laranail.db-tools.backup.mysql.routines', false)) {
                $command[] = '--routines';
                $command[] = '--events';
            }

            // A GTID server makes mysqldump emit SET @@GLOBAL.GTID_PURGED,
            // which needs SUPER to replay. MariaDB's client has no such option
            // and exits on an unknown one, so pass it only where it exists.
            if ($this->clientSupports($binary, '--set-gtid-purged')) {
                $command[] = '--set-gtid-purged=OFF';
            }

            foreach ($this->excludedTables() as $table) {
                $command[] = '--ignore-table=' . $config['database'] . '.' . $table;
            }

            $command[] = $config['database'];
            $command[] = '--result-file=' . $dumpPath;

            $result = $this->backupProcess()->run($command);

            if (! $result->successful()) {
                throw new RuntimeException('MySQL backup failed: ' . $result->errorOutput());
            }
        } finally {
            @unlink($defaultsFile);
        }

        if ($gzip) {
            $this->gzipCompressFile($dumpPath, $path);
        }

        return true;
    }

    /**
     * Restore a MySQL backup by streaming the dump through the mysql client.
     *
     * The dump is fed line by line rather than read into memory, and DEFINER
     * clauses are removed on the way: on a managed server the restoring user
     * cannot create objects owned by another account (error 1227).
     *
     * @param array $config Database connection configuration
     * @param string $path Absolute path to the dump (".sql" or ".sql.gz")
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
            throw new RuntimeException("MySQL backup file not found: {$path}");
        }

        // Decompress gzipped dumps to a temp file we feed to the client. The
        // decompression verifies the archive is complete before anything is
        // replayed, which streaming straight from gzopen could not.
        $sqlPath = $path;
        $tempPath = null;

        if ($this->isGzipPath($path)) {
            $tempPath = $this->makeTempFile('.sql');
            $this->gzipDecompressFile($path, $tempPath);
            $sqlPath = $tempPath;
        }

        $binary = $this->binary('mysql');
        $defaultsFile = null;
        $handle = null;

        try {
            $defaultsFile = $this->writeDefaultsFile($config);

            $command = [
                $binary,
                '--defaults-extra-file=' . $defaultsFile,
                ...$this->connectionArguments($config, $binary),
                $config['database'],
            ];

            $handle = @fopen($sqlPath, 'rb');

            if ($handle === false) {
                throw new RuntimeException("Unable to read MySQL backup file: {$sqlPath}");
            }

            $result = $this->backupProcess()->input($this->linesWithoutDefiners($handle, $sqlPath))->run($command);

            if (! $result->successful()) {
                throw new RuntimeException('MySQL restore failed: ' . $result->errorOutput());
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if ($defaultsFile !== null) {
                @unlink($defaultsFile);
            }

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
     * @return bool True if driver is mysql or mariadb
     */
    public function supports(string $driver): bool
    {
        return in_array($driver, ['mysql', 'mariadb'], true);
    }

    /**
     * Host, port, user, socket and TLS arguments shared by mysqldump and mysql.
     *
     * @return array<int, string>
     */
    private function connectionArguments(array $config, string $binary): array
    {
        $arguments = [
            '--host=' . ($config['host'] ?? '127.0.0.1'),
            '--port=' . ($config['port'] ?? '3306'),
            '--user=' . $config['username'],
        ];

        if (! empty($config['socket'])) {
            $arguments[] = '--socket=' . $config['socket'];
        }

        return [...$arguments, ...$this->tlsArguments($config, $binary)];
    }

    /**
     * Map the connection's TLS settings onto client flags.
     *
     * The sources are the PDO options the application already connects with
     * (`options` keyed by Pdo\Mysql::ATTR_SSL_*), and an explicit `ssl-mode`
     * (`?ssl-mode=REQUIRED` on a provider's URL lands under that key). A CA
     * without an explicit mode verifies the server's identity, as mysqlnd does;
     * ATTR_SSL_VERIFY_SERVER_CERT=false downgrades that to encryption only.
     *
     * @return array<int, string>
     *
     * @throws RuntimeException If the configured ssl-mode is not recognised
     */
    private function tlsArguments(array $config, string $binary): array
    {
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];

        $ca = $this->tlsValue($options, self::ATTR_SSL_CA, $config, ['ssl-ca', 'ssl_ca']);
        $capath = $this->tlsValue($options, self::ATTR_SSL_CAPATH, $config, ['ssl-capath', 'ssl_capath']);
        $cert = $this->tlsValue($options, self::ATTR_SSL_CERT, $config, ['ssl-cert', 'ssl_cert']);
        $key = $this->tlsValue($options, self::ATTR_SSL_KEY, $config, ['ssl-key', 'ssl_key']);
        $cipher = $this->tlsValue($options, self::ATTR_SSL_CIPHER, $config, ['ssl-cipher', 'ssl_cipher']);
        $verify = $options[self::ATTR_SSL_VERIFY_SERVER_CERT] ?? null;

        $mode = $this->explicitSslMode($config);

        if ($mode === null) {
            if ($ca !== null || $capath !== null) {
                $mode = $verify === false ? 'REQUIRED' : 'VERIFY_IDENTITY';
            } elseif ($cert !== null || $key !== null) {
                $mode = 'REQUIRED';
            } else {
                return [];
            }

        }

        // REQUIRED encrypts without verifying. Handed a CA, the MySQL client
        // verifies anyway, failing where the application connects.
        if ($mode === 'REQUIRED') {
            $ca = $capath = null;
        }

        if ($this->clientSupports($binary, '--ssl-mode')) {
            $arguments = ['--ssl-mode=' . $mode];
        } else {
            // MariaDB's client has no --ssl-mode.
            $arguments = match ($mode) {
                'DISABLED'  => ['--skip-ssl'],
                'PREFERRED' => [],
                // MariaDB 11.4+ verifies by default; REQUIRED must not.
                'REQUIRED' => ['--ssl', '--skip-ssl-verify-server-cert'],
                default    => ['--ssl', '--ssl-verify-server-cert'],
            };
        }

        if ($mode === 'DISABLED') {
            return $arguments;
        }

        foreach (['--ssl-ca' => $ca, '--ssl-capath' => $capath, '--ssl-cert' => $cert, '--ssl-key' => $key, '--ssl-cipher' => $cipher] as $flag => $value) {
            if ($value !== null) {
                $arguments[] = $flag . '=' . $value;
            }
        }

        return $arguments;
    }

    /**
     * @param array<int|string, mixed> $options
     * @param array<int, string> $keys
     */
    private function tlsValue(array $options, int $attribute, array $config, array $keys): ?string
    {
        foreach ([$options[$attribute] ?? null, ...array_map(static fn (string $key): mixed => $config[$key] ?? null, $keys)] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @throws RuntimeException If the configured ssl-mode is not recognised
     */
    private function explicitSslMode(array $config): ?string
    {
        foreach (['ssl-mode', 'ssl_mode', 'sslmode'] as $key) {
            $value = $config[$key] ?? null;

            if (! is_string($value) || $value === '') {
                continue;
            }

            $normalised = strtoupper(str_replace('-', '_', trim($value)));

            // Fail closed: a typo must not silently downgrade to no TLS.
            return self::SSL_MODES[$normalised] ?? throw new RuntimeException(sprintf(
                'Unrecognised MySQL ssl-mode [%s]; expected one of %s.',
                $value,
                implode(', ', array_unique(self::SSL_MODES)),
            ));
        }

        return null;
    }

    /**
     * Whether the client's --help lists $option.
     */
    private function clientSupports(string $binary, string $option): bool
    {
        if (! array_key_exists($binary, $this->clientHelp)) {
            // A failed probe is not cached as "supports nothing": that would
            // send a MySQL 8 client MariaDB's --ssl, which it rejects, and
            // drop --set-gtid-purged. Fail with the reason instead.
            try {
                $result = Process::run([$binary, '--help']);
            } catch (Throwable $e) {
                throw new RuntimeException("Could not run [{$binary} --help] to detect its options: {$e->getMessage()}", 0, $e);
            }

            if (! $result->successful()) {
                throw new RuntimeException("Could not run [{$binary} --help] to detect its options: " . trim($result->errorOutput()));
            }

            $this->clientHelp[$binary] = $result->output();
        }

        return str_contains($this->clientHelp[$binary], $option);
    }

    /**
     * Write the password to a 0600 option file for --defaults-extra-file.
     *
     * MYSQL_PWD is deprecated and readable from the process environment; a
     * password on argv is visible to every user through `ps`. The caller
     * deletes the file as soon as the client exits.
     *
     * @throws RuntimeException If the file cannot be written
     */
    private function writeDefaultsFile(array $config): string
    {
        $path = $this->makeTempFile('.cnf');

        // Option-file values are quoted, and inside quotes the client honours
        // these escapes; `#` and `;` are literal there.
        $password = strtr((string) $config['password'], [
            '\\' => '\\\\',
            '"'  => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]);

        if (file_put_contents($path, "[client]\npassword=\"{$password}\"\n") === false) {
            @unlink($path);

            throw new RuntimeException('Unable to write the MySQL client option file.');
        }

        return $path;
    }

    /**
     * Stream a dump line by line with DEFINER clauses removed.
     *
     * @param resource $handle
     *
     * @return Generator<int, string>
     */
    private function linesWithoutDefiners($handle, string $path): Generator
    {
        $strip = (bool) Config::get('laranail.db-tools.backup.mysql.strip_definers', true);

        while (($line = fgets($handle)) !== false) {
            yield $strip ? self::stripDefiner($line) : $line;
        }

        if (! feof($handle)) {
            throw new RuntimeException("Failed to read MySQL backup file: {$path}");
        }
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
        return $this->isGzipPath($path) ? substr($path, 0, -3) : $path . '.sql';
    }
}
