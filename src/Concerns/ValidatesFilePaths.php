<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Illuminate\Support\Str;

/**
 * File path validation and normalization helpers, centralised so database
 * utilities share one security-aware implementation.
 */
trait ValidatesFilePaths
{
    /**
     * Normalize a path: strip null bytes, unify separators, resolve relatives.
     */
    protected function normalizePath(string $path): string
    {
        $path = Str::replace("\0", '', $path);
        $path = Str::replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        $path = Str::rtrim($path, DIRECTORY_SEPARATOR);

        if (! $this->isAbsolutePath($path)) {
            return base_path($path);
        }

        return $path;
    }

    /**
     * Whether the path is absolute (Unix or Windows).
     */
    protected function isAbsolutePath(string $path): bool
    {
        if (Str::startsWith($path, DIRECTORY_SEPARATOR)) {
            return true;
        }

        return (bool) preg_match('/^[A-Z]:[\\\\\/]/i', $path);
    }

    /**
     * Whether the path is a readable PHP file (and traversal-free).
     */
    protected function isValidPhpFile(string $path): bool
    {
        if ($this->hasDirectoryTraversal($path)) {
            return false;
        }

        return is_file($path)
            && is_readable($path)
            && pathinfo($path, PATHINFO_EXTENSION) === 'php';
    }

    /**
     * Whether the path is a readable directory (and traversal-free).
     */
    protected function isValidDirectory(string $path): bool
    {
        if ($this->hasDirectoryTraversal($path)) {
            return false;
        }

        return is_dir($path) && is_readable($path);
    }

    /**
     * Whether the path contains a directory-traversal sequence.
     *
     * Splits on both separators and inspects each segment for a literal `..`,
     * so legitimate names that merely embed two dots (e.g. "my..file.sql") are
     * not flagged, while real `../` / `..\` traversal segments are. This is the
     * lexical guard; pair it with isContainedWithin() when a trusted base
     * directory is available for realpath-based containment.
     */
    protected function hasDirectoryTraversal(string $path): bool
    {
        $segments = preg_split('#[\\\\/]+#', $path) ?: [];

        return in_array('..', $segments, true);
    }

    /**
     * Whether $path resolves to a location inside $base (realpath-based).
     *
     * Prefer this over the lexical hasDirectoryTraversal() check when a trusted
     * base directory exists: it resolves symlinks and `..` segments before
     * comparing, so a path that escapes the base — by any means — is rejected.
     * Returns false when either path cannot be resolved (e.g. does not exist).
     */
    protected function isContainedWithin(string $path, string $base): bool
    {
        $realBase = realpath($base);
        $realPath = realpath($path);

        if ($realBase === false || $realPath === false) {
            return false;
        }

        $realBase = Str::rtrim($realBase, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if ($realPath === Str::rtrim($realBase, DIRECTORY_SEPARATOR)) {
            return true;
        }

        return Str::startsWith($realPath.DIRECTORY_SEPARATOR, $realBase);
    }

    /**
     * Lower-cased file extension, or null when there is none.
     */
    protected function getFileExtension(string $path): ?string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension !== '' ? Str::lower($extension) : null;
    }

    /**
     * File name without its extension.
     */
    protected function getFileNameWithoutExtension(string $path): string
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }

    /**
     * Whether the file exists and is within the given size limit.
     */
    protected function isFileSizeWithinLimit(string $path, int $maxBytes): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $size = filesize($path);

        return $size !== false && $size <= $maxBytes;
    }
}
