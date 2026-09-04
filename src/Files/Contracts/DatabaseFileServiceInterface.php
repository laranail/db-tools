<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Files\Contracts;

use RuntimeException;

/**
 * Interface DatabaseFileServiceInterface
 *
 * Provides database file validation and import functionality.
 * Ensures secure file handling with proper validation.
 */
interface DatabaseFileServiceInterface
{
    /**
     * Validate database file exists and is readable
     *
     * @param string $filePath Path to database file
     *
     * @return string|false Validated path or false if invalid
     */
    public function validateDatabaseFile(string $filePath): string|false;

    /**
     * Handle database file import
     *
     * @param string $filePath Path to database file
     * @param string|null $connection Connection name (null for default)
     *
     * @throws RuntimeException If file is invalid or import fails
     */
    public function handleImport(string $filePath, ?string $connection = null): void;

    /**
     * Check if file is a valid database file
     *
     * @param string $filePath Path to check
     *
     * @return bool True if valid database file
     */
    public function isValidDatabaseFile(string $filePath): bool;

    /**
     * Get supported database file extensions
     *
     * @return array Supported extensions
     */
    public function getSupportedExtensions(): array;

    /**
     * Validate file size is within limits
     *
     * @param string $filePath Path to file
     * @param int $maxSize Maximum file size in bytes
     *
     * @return bool True if within limits
     */
    public function validateFileSize(string $filePath, int $maxSize = 104857600): bool;
}
