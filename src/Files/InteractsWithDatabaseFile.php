<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Files;

use RuntimeException;
use Simtabi\Laranail\DbTools\Files\Contracts\DatabaseFileServiceInterface;

/**
 * Trait InteractsWithDatabaseFile
 *
 * Thin wrapper trait that delegates to DatabaseFileService.
 * Provides database file validation and import functionality.
 *
 * Usage:
 * ```php
 * class MyController
 * {
 *     use InteractsWithDatabaseFile;
 *
 *     public function import(Request $request): void
 *     {
 *         $file = $request->file('database_file');
 *
 *         if ($this->isValidDatabaseFile($file->path())) {
 *             $this->handleImportDatabaseFile($file->path());
 *         }
 *     }
 * }
 * ```
 */
trait InteractsWithDatabaseFile
{
    /**
     * Get the database file service instance
     */
    protected function databaseFile(): DatabaseFileServiceInterface
    {
        return app(DatabaseFileServiceInterface::class);
    }

    /**
     * Handle database file import
     *
     * @param string $filePath Path to database file
     * @param string|null $connection Target connection (null for the default)
     *
     * @throws RuntimeException If file is invalid or import fails
     */
    protected function handleImportDatabaseFile(string $filePath, ?string $connection = null): void
    {
        $this->databaseFile()->handleImport($filePath, $connection);
    }

    /**
     * Validate database file exists and is readable
     *
     * @param string $filePath Path to database file
     *
     * @return string|bool Validated path or false if invalid
     */
    protected function validateDatabaseFile(string $filePath): string|bool
    {
        return $this->databaseFile()->validateDatabaseFile($filePath);
    }

    /**
     * Check if file is a valid database file
     *
     * @param string $filePath Path to check
     *
     * @return bool True if valid database file
     */
    protected function isValidDatabaseFile(string $filePath): bool
    {
        return $this->databaseFile()->isValidDatabaseFile($filePath);
    }

    /**
     * Validate file size is within limits
     *
     * @param string $filePath Path to file
     * @param int $maxSize Maximum file size in bytes (default: 100MB)
     *
     * @return bool True if within limits
     */
    protected function validateFileSize(string $filePath, int $maxSize = 104857600): bool
    {
        return $this->databaseFile()->validateFileSize($filePath, $maxSize);
    }

    /**
     * Get supported database file extensions
     *
     * @return array List of supported extensions (e.g., ['sql', 'gz', 'zip'])
     */
    protected function getSupportedExtensions(): array
    {
        return $this->databaseFile()->getSupportedExtensions();
    }
}
