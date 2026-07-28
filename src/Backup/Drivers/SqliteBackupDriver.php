<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Backup\Drivers;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Simtabi\Laranail\DbTools\Backup\Concerns\ResolvesBackupOptions;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupDriverInterface;
use Simtabi\Laranail\DbTools\Backup\SqlFileRestorer;

/**
 * Class SqliteBackupDriver
 *
 * Handles SQLite database backups by copying the database file, and restores
 * either by replaying a ".sql" dump (via SqlFileRestorer) or by copying a
 * file-copy backup back over the database. Simple and efficient for
 * file-based databases.
 */
class SqliteBackupDriver implements BackupDriverInterface
{
    /** SQLite's write-ahead-log sidecars, which travel with the database file. */
    private const array WAL_EXTENSIONS = ['-wal', '-shm'];

    use ResolvesBackupOptions;

    /**
     * Create a SQLite backup by copying the database file
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

        $databasePath = $config['database'];

        if ($databasePath === ':memory:') {
            throw new RuntimeException('Cannot backup in-memory SQLite database');
        }

        if (! File::exists($databasePath)) {
            throw new RuntimeException("SQLite database file not found: {$databasePath}");
        }

        $backupDir = dirname($path);
        if (! File::exists($backupDir)) {
            File::makeDirectory($backupDir, 0755, true);
        }

        if ($this->gzipEnabled()) {
            // Stream the database file straight into a gzip archive.
            $this->gzipCompressFileKeepingSource($databasePath, $path);

            return true;
        }

        if (! File::copy($databasePath, $path)) {
            throw new RuntimeException('Failed to copy SQLite database file');
        }

        $this->copyWalFiles($databasePath, $path);

        return true;
    }

    /**
     * Restore a SQLite backup.
     *
     * A ".sql" (or ".sql.gz") text dump is replayed through SqlFileRestorer; a
     * file-copy backup (optionally gzipped) is copied back over the configured
     * database file.
     *
     * @param  array  $config  Database connection configuration
     * @param  string  $path  Absolute path to the backup file
     * @param  string|null  $connection  Connection name (null for default)
     * @return bool True if restore successful
     *
     * @throws RuntimeException If restore fails
     */
    public function restore(array $config, string $path, ?string $connection = null): bool
    {
        $this->validateConfig($config);

        if (! is_file($path)) {
            throw new RuntimeException("SQLite backup file not found: {$path}");
        }

        // Decompress gzipped backups to a temp file first.
        $sourcePath = $path;
        $tempPath = null;

        if ($this->isGzipPath($path)) {
            $tempPath = $this->makeTempFile();
            $this->gzipDecompressFile($path, $tempPath);
            $sourcePath = $tempPath;
            $path = substr($path, 0, -3); // underlying format for the ext check
        }

        try {
            if ($this->isPlainSqlPath($path)) {
                // Replay a SQL text dump into the live connection.
                return (new SqlFileRestorer)->restore($sourcePath, $connection);
            }

            $databasePath = $config['database'];

            if ($databasePath === ':memory:') {
                throw new RuntimeException('Cannot restore a file backup into an in-memory SQLite database');
            }

            // The live -wal/-shm belong to the database being REPLACED. Left in
            // place, SQLite replays that newer WAL over the restored file on the
            // next open, so post-backup writes come back from the dead — or the
            // salt no longer matches the new header and the file is corrupt.
            $this->removeWalFiles($databasePath);

            if (! File::copy($sourcePath, $databasePath)) {
                throw new RuntimeException('Failed to restore SQLite database file');
            }

            // backup() captures the sidecars, so a row committed to the WAL but
            // not yet checkpointed into the main file lives only there. Not
            // restoring them silently dropped that data.
            $this->restoreWalFiles($sourcePath, $databasePath);
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
     * @return bool True if driver is sqlite
     */
    public function supports(string $driver): bool
    {
        return $driver === 'sqlite';
    }

    /**
     * Validate configuration has required fields
     *
     * @throws RuntimeException If configuration is invalid
     */
    private function validateConfig(array $config): void
    {
        if (empty($config['database'])) {
            throw new RuntimeException("SQLite backup requires 'database' configuration");
        }
    }

    /**
     * Whether the path is a plain ".sql" text dump.
     */
    private function isPlainSqlPath(string $path): bool
    {
        return str_ends_with(strtolower($path), '.sql');
    }

    /**
     * Gzip-compress a source file into a destination without deleting the
     * source (the source here is the live database, which must stay put).
     *
     * @throws RuntimeException If compression fails
     */
    private function gzipCompressFileKeepingSource(string $source, string $destination): void
    {
        $in = @fopen($source, 'rb');
        $out = @gzopen($destination, 'wb9');

        if ($in === false || $out === false) {
            if ($in !== false) {
                fclose($in);
            }
            if ($out !== false) {
                gzclose($out);
            }

            throw new RuntimeException("Failed to gzip-compress SQLite backup: {$source}");
        }

        while (! feof($in)) {
            $chunk = fread($in, 262144);
            if ($chunk === false) {
                break;
            }
            gzwrite($out, $chunk);
        }

        fclose($in);
        gzclose($out);
    }

    /**
     * Copy WAL (Write-Ahead Logging) and SHM (Shared Memory) files if they exist
     *
     * @param  string  $sourcePath  Original database path
     * @param  string  $targetPath  Backup database path
     */
    /**
     * Remove a database's WAL/SHM sidecars.
     */
    private function removeWalFiles(string $databasePath): void
    {
        foreach (self::WAL_EXTENSIONS as $ext) {
            if (File::exists($databasePath.$ext)) {
                File::delete($databasePath.$ext);
            }
        }
    }

    /**
     * Put a backup's sidecars back alongside the restored database.
     */
    private function restoreWalFiles(string $sourcePath, string $databasePath): void
    {
        foreach (self::WAL_EXTENSIONS as $ext) {
            if (File::exists($sourcePath.$ext)) {
                File::copy($sourcePath.$ext, $databasePath.$ext);
            }
        }
    }

    private function copyWalFiles(string $sourcePath, string $targetPath): void
    {
        foreach (self::WAL_EXTENSIONS as $ext) {
            $sourceFile = $sourcePath.$ext;
            $targetFile = $targetPath.$ext;

            if (File::exists($sourceFile)) {
                File::copy($sourceFile, $targetFile);
            }
        }
    }
}
