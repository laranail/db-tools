<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Files;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupManagerInterface;
use Simtabi\Laranail\DbTools\Files\Contracts\DatabaseFileServiceInterface;

/**
 * Database File Service
 *
 * Validates and imports database files with size, extension and path-traversal
 * checks to ensure proper, secure file handling.
 */
class DatabaseFileService implements DatabaseFileServiceInterface
{
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
     * Validate database file exists and is readable
     *
     * @param  string  $filePath  Path to database file
     * @return string|false Validated path or false if invalid
     */
    public function validateDatabaseFile(string $filePath): string|false
    {
        // Security: Prevent path traversal attacks
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
     * Handle database file import.
     *
     * Validates the path/extension/size, then delegates the actual load to the
     * driver-aware {@see BackupManagerInterface::restore()} — which dispatches
     * SQL text, PostgreSQL custom-format dumps and SQLite files to the correct
     * tool. No shell calls happen here; the backup drivers shell out safely.
     *
     * @param  string  $filePath  Path to database file (`.sql` or `.dump`)
     * @param  string|null  $connection  Connection name (null for default)
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
                .'Provide a ".sql" or ".dump" backup file, or use the backup/restore APIs directly.'
            );
        }

        app(BackupManagerInterface::class)->restore($validatedPath, $connection);
    }

    /**
     * Check if file is a valid database file
     *
     * @param  string  $filePath  Path to check
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
     * @param  string  $filePath  Path to file
     * @param  int  $maxSize  Maximum file size in bytes
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
     * @param  string  $filePath  Path to file
     * @return array File information
     */
    public function getFileInfo(string $filePath): array
    {
        $validatedPath = $this->validateDatabaseFile($filePath);

        if (! $validatedPath) {
            return [];
        }

        return [
            'path' => $validatedPath,
            'size' => File::size($validatedPath),
            'extension' => File::extension($validatedPath),
            'name' => File::name($validatedPath),
            'basename' => File::basename($validatedPath),
            'is_valid' => $this->isValidDatabaseFile($validatedPath),
            'is_readable' => File::isReadable($validatedPath),
            'is_writable' => File::isWritable($validatedPath),
        ];
    }

    /**
     * Static factory method
     */
    public static function create(): self
    {
        return new self;
    }
}
