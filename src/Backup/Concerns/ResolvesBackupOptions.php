<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Backup\Concerns;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Shared backup/restore option resolution: optional binary paths, gzip toggle
 * and excluded-table list, all read from the package's "backup" config.
 *
 * Keeping these in one trait means every driver honours the same
 * config('laranail.db-tools.backup.*') keys consistently.
 */
trait ResolvesBackupOptions
{
    /**
     * Resolve a CLI binary, honouring an optional absolute path override from
     * config('laranail.db-tools.backup.binaries.*') and otherwise falling back to
     * the bare name (resolved via PATH by the process runner).
     */
    protected function binary(string $name): string
    {
        $configured = Config::get("laranail.db-tools.backup.binaries.{$name}");

        return is_string($configured) && $configured !== '' ? $configured : $name;
    }

    /**
     * Whether dumps should be gzip-compressed.
     */
    protected function gzipEnabled(): bool
    {
        return (bool) Config::get('laranail.db-tools.backup.gzip', false);
    }

    /**
     * Tables to omit from the dump.
     *
     * @return array<int, string>
     */
    protected function excludedTables(): array
    {
        $tables = Config::get('laranail.db-tools.backup.exclude', []);

        if (! is_array($tables)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($t): string => (string) $t, $tables),
            static fn (string $t): bool => $t !== '',
        ));
    }

    /**
     * Whether a path is a gzip-compressed file (by ".gz" extension).
     */
    protected function isGzipPath(string $path): bool
    {
        return str_ends_with(strtolower($path), '.gz');
    }

    /**
     * Create a temporary file path for staging a decompressed dump.
     *
     * @throws RuntimeException If a temp file cannot be created
     */
    protected function makeTempFile(string $suffix = ''): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'dbt-restore-');

        if ($temp === false) {
            throw new RuntimeException('Unable to create a temporary file for restore.');
        }

        if ($suffix === '') {
            return $temp;
        }

        $withSuffix = $temp.$suffix;
        @rename($temp, $withSuffix);

        return $withSuffix;
    }

    /**
     * Gzip-compress $source into $destination, streaming so large dumps don't
     * have to be held in memory. The source file is removed on success.
     *
     * @throws RuntimeException If compression fails
     */
    protected function gzipCompressFile(string $source, string $destination): void
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

            throw new RuntimeException("Failed to gzip-compress backup: {$source}");
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

        @unlink($source);
    }

    /**
     * Gzip-decompress $source into $destination, streaming.
     *
     * @throws RuntimeException If decompression fails
     */
    protected function gzipDecompressFile(string $source, string $destination): void
    {
        $in = @gzopen($source, 'rb');
        $out = @fopen($destination, 'wb');

        if ($in === false || $out === false) {
            if ($in !== false) {
                gzclose($in);
            }
            if ($out !== false) {
                fclose($out);
            }

            throw new RuntimeException("Failed to gzip-decompress backup: {$source}");
        }

        while (! gzeof($in)) {
            $chunk = gzread($in, 262144);
            if ($chunk === false) {
                break;
            }
            fwrite($out, $chunk);
        }

        gzclose($in);
        fclose($out);
    }
}
