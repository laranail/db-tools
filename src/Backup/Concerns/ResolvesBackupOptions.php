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

        // Every write is checked. Ignoring gzwrite's return meant a full disk
        // produced a truncated archive, and the unconditional unlink below then
        // destroyed the only complete copy — while reporting success.
        try {
            while (! feof($in)) {
                $chunk = fread($in, 262144);

                if ($chunk === false) {
                    throw new RuntimeException("Failed to read backup while compressing: {$source}");
                }

                if ($chunk === '') {
                    continue;
                }

                if (gzwrite($out, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException("Short write while gzip-compressing backup (disk full?): {$destination}");
                }
            }
        } catch (RuntimeException $e) {
            fclose($in);
            gzclose($out);
            @unlink($destination);

            throw $e;
        }

        fclose($in);

        // gzclose flushes the deflate buffer and writes the trailer, so it can
        // fail even when every gzwrite succeeded.
        if (! gzclose($out)) {
            @unlink($destination);

            throw new RuntimeException("Failed to finalise gzip archive: {$destination}");
        }

        // Only now is the archive known to be complete.
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

        $written = 0;

        try {
            while (! gzeof($in)) {
                $chunk = gzread($in, 262144);

                if ($chunk === false) {
                    throw new RuntimeException("Failed to read gzip archive: {$source}");
                }

                if ($chunk === '') {
                    continue;
                }

                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException("Short write while gzip-decompressing backup (disk full?): {$destination}");
                }

                $written += strlen($chunk);
            }
        } catch (RuntimeException $e) {
            gzclose($in);
            fclose($out);
            @unlink($destination);

            throw $e;
        }

        gzclose($in);
        fclose($out);

        // gzread does NOT report a truncated stream — it simply stops, and the
        // loop then exits through gzeof. A short dump replayed into a database
        // is worse than a failed restore, so compare what we wrote against the
        // uncompressed size recorded in the gzip trailer.
        $expected = $this->gzipUncompressedSize($source);

        if ($expected !== null && $expected !== $written % 4294967296) {
            @unlink($destination);

            throw new RuntimeException(sprintf(
                'Gzip archive is truncated or corrupt: %s (expected %d bytes, got %d).',
                $source,
                $expected,
                $written % 4294967296,
            ));
        }
    }

    /**
     * The uncompressed size recorded in a gzip trailer (ISIZE), or null when it
     * cannot be read.
     *
     * ISIZE is the original size modulo 2^32, stored little-endian in the last
     * four bytes. Exact for archives under 4 GiB and a strong truncation signal
     * regardless.
     */
    private function gzipUncompressedSize(string $path): ?int
    {
        $size = @filesize($path);

        if ($size === false || $size < 4) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            if (fseek($handle, -4, SEEK_END) !== 0) {
                return null;
            }

            $trailer = fread($handle, 4);

            if ($trailer === false || strlen($trailer) !== 4) {
                return null;
            }

            /** @var array{1: int}|false $unpacked */
            $unpacked = unpack('V', $trailer);

            return $unpacked === false ? null : $unpacked[1];
        } finally {
            fclose($handle);
        }
    }
}
