<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Files;

use RuntimeException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Simtabi\Laranail\DbTools\Concerns\ValidatesFilePaths;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupManagerInterface;
use Simtabi\Laranail\DbTools\Files\Contracts\DatabaseFileServiceInterface;

/**
 * Database File Service
 *
 * Validates and imports database files: existence, readability, extension, size,
 * and — when `laranail.db-tools.files.import_base` is set — containment within a
 * permitted directory.
 *
 * Note that realpath() alone is NOT a traversal check: it resolves `..` and
 * symlinks rather than rejecting them, so without a base directory to confine
 * against, any readable file on the filesystem is reachable. That is why
 * containment is explicit and configurable rather than implied.
 */
class DatabaseFileService implements DatabaseFileServiceInterface
{
    use ValidatesFilePaths;

    /**
     * Supported database file extensions
     */
    private const array SUPPORTED_EXTENSIONS = [
        'sql',
        'sqlite',
        'db',
        'dump',
    ];

    /**
     * Default maximum file size (100MB)
     */
    private const int DEFAULT_MAX_SIZE = 104857600;

    /**
     * Importable formats this service delegates to the backup drivers.
     *
     * A live SQLite database file (`.sqlite` / `.db`) is intentionally NOT in
     * this set: importing it would mean swapping the running database file,
     * which is out of scope for an "import a dump" operation.
     */
    private const array IMPORTABLE_EXTENSIONS = [
        'sql',
        'dump',
    ];

    /**
     * Static factory method
     */
    public static function create(): self
    {
        return new self;
    }

    /**
     * Validate database file exists and is readable
     *
     * @param string $filePath Path to database file
     *
     * @return string|false Validated path or false if invalid
     */
    public function validateDatabaseFile(string $filePath): string|false
    {
        // Canonicalise only. This resolves `..` and symlinks; it does not
        // reject them. Containment is enforced separately in handleImport().
        $realPath = realpath($filePath);

        if ($realPath === false) {
            return false;
        }

        if (! File::exists($realPath) || ! File::isReadable($realPath)) {
            return false;
        }

        if (! File::isFile($realPath)) {
            return false;
        }

        return $realPath;
    }

    /**
     * Handle database file import.
     *
     * Validates the path/extension/size, then delegates the actual load to the
     * driver-aware {@see BackupManagerInterface::restore()} — which dispatches
     * SQL text, PostgreSQL custom-format dumps and SQLite files to the correct
     * tool. No shell calls happen here; the backup drivers shell out safely.
     *
     * @param string $filePath Path to database file (`.sql` or `.dump`)
     * @param string|null $connection Connection name (null for default)
     *
     * @throws RuntimeException If the file is invalid or the format cannot be imported
     */
    public function handleImport(string $filePath, ?string $connection = null): void
    {
        $validatedPath = $this->validateDatabaseFile($filePath);

        if (! $validatedPath) {
            throw new RuntimeException("Database file not found or not readable: '{$filePath}'");
        }

        if (! $this->isValidDatabaseFile($validatedPath)) {
            throw new RuntimeException("Invalid database file type: '{$filePath}'");
        }

        if (! $this->validateFileSize($validatedPath)) {
            throw new RuntimeException("Database file exceeds maximum size: '{$filePath}'");
        }

        $extension = Str::lower(File::extension($validatedPath));

        // Refuse to swap a live SQLite database file in place — only dumps are
        // importable here. The backup drivers handle replaying dumps (incl.
        // PostgreSQL custom-format dumps) into the target connection.
        if (! in_array($extension, self::IMPORTABLE_EXTENSIONS, true)) {
            throw new RuntimeException(
                "Importing a '{$extension}' file is not supported: '{$filePath}'. "
                . 'Provide a ".sql" or ".dump" backup file, or use the backup/restore APIs directly.',
            );
        }

        if (! $this->isWithinImportBase($validatedPath)) {
            throw new RuntimeException(
                "Refusing to import '{$filePath}': it resolves outside the permitted import directory "
                . '(laranail.db-tools.files.import_base).',
            );
        }

        app(BackupManagerInterface::class)->restore($validatedPath, $connection);
    }

    /**
     * Check if file is a valid database file
     *
     * @param string $filePath Path to check
     *
     * @return bool True if valid database file
     */
    public function isValidDatabaseFile(string $filePath): bool
    {
        $extension = Str::lower(File::extension($filePath));

        return in_array($extension, self::SUPPORTED_EXTENSIONS, true);
    }

    /**
     * Get supported database file extensions
     *
     * @return array Supported extensions
     */
    public function getSupportedExtensions(): array
    {
        return self::SUPPORTED_EXTENSIONS;
    }

    /**
     * Validate file size is within limits
     *
     * @param string $filePath Path to file
     * @param int $maxSize Maximum file size in bytes
     *
     * @return bool True if within limits
     */
    public function validateFileSize(string $filePath, int $maxSize = self::DEFAULT_MAX_SIZE): bool
    {
        if (! File::exists($filePath)) {
            return false;
        }

        return File::size($filePath) <= $maxSize;
    }

    /**
     * Get file information
     *
     * @param string $filePath Path to file
     *
     * @return array File information
     */
    public function getFileInfo(string $filePath): array
    {
        $validatedPath = $this->validateDatabaseFile($filePath);

        if (! $validatedPath) {
            return [];
        }

        return [
            'path'        => $validatedPath,
            'size'        => File::size($validatedPath),
            'extension'   => File::extension($validatedPath),
            'name'        => File::name($validatedPath),
            'basename'    => File::basename($validatedPath),
            'is_valid'    => $this->isValidDatabaseFile($validatedPath),
            'is_readable' => File::isReadable($validatedPath),
            'is_writable' => File::isWritable($validatedPath),
        ];
    }

    /**
     * Whether the resolved path sits inside the configured import directory.
     *
     * Returns true when no base is configured, so an application whose dumps
     * live elsewhere keeps working — the confinement is opt-in rather than a
     * silent breaking change, but it is documented and defaulted in the shipped
     * config.
     */
    private function isWithinImportBase(string $realPath): bool
    {
        $base = config('laranail.db-tools.files.import_base');

        if (! is_string($base) || $base === '') {
            return true;
        }

        return $this->isContainedWithin($realPath, $base);
    }
}
