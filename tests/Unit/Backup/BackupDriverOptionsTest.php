<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Backup;

use RuntimeException;
use Simtabi\Laranail\DbTools\Backup\Concerns\ResolvesBackupOptions;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class BackupDriverOptionsTest extends TestCase
{
    /**
     * Small probe exposing the protected trait methods so we can assert option
     * resolution without driving a real database CLI.
     */
    private function probe(): object
    {
        return new class
        {
            use ResolvesBackupOptions;

            public function callBinary(string $name): string
            {
                return $this->binary($name);
            }

            public function callGzipEnabled(): bool
            {
                return $this->gzipEnabled();
            }

            /** @return array<int, string> */
            public function callExcludedTables(): array
            {
                return $this->excludedTables();
            }

            public function callIsGzipPath(string $path): bool
            {
                return $this->isGzipPath($path);
            }

            public function callGzipCompress(string $source, string $destination): void
            {
                $this->gzipCompressFile($source, $destination);
            }

            public function callGzipDecompress(string $source, string $destination): void
            {
                $this->gzipDecompressFile($source, $destination);
            }
        };
    }

    public function test_gzip_disabled_by_default_and_toggled_by_config(): void
    {
        $probe = $this->probe();

        self::assertFalse($probe->callGzipEnabled());

        config()->set('laranail.db-tools.backup.gzip', true);

        self::assertTrue($probe->callGzipEnabled());
    }

    public function test_is_gzip_path_detects_gz_extension_case_insensitively(): void
    {
        $probe = $this->probe();

        self::assertTrue($probe->callIsGzipPath('/tmp/dump.sql.gz'));
        self::assertTrue($probe->callIsGzipPath('/tmp/DUMP.SQL.GZ'));
        self::assertFalse($probe->callIsGzipPath('/tmp/dump.sql'));
    }

    public function test_excluded_tables_read_from_config(): void
    {
        $probe = $this->probe();

        self::assertSame([], $probe->callExcludedTables());

        config()->set('laranail.db-tools.backup.exclude', ['sessions', 'cache', '', 'jobs']);

        // Empty entries are dropped and the list is re-indexed.
        self::assertSame(['sessions', 'cache', 'jobs'], $probe->callExcludedTables());
    }

    public function test_excluded_tables_returns_empty_when_config_not_an_array(): void
    {
        config()->set('laranail.db-tools.backup.exclude', 'not-an-array');

        self::assertSame([], $this->probe()->callExcludedTables());
    }

    public function test_binary_falls_back_to_bare_name_without_override(): void
    {
        config()->set('laranail.db-tools.backup.binaries.mysqldump');

        self::assertSame('mysqldump', $this->probe()->callBinary('mysqldump'));
    }

    public function test_binary_honours_configured_absolute_path(): void
    {
        config()->set('laranail.db-tools.backup.binaries.pg_dump', '/usr/local/bin/pg_dump');

        self::assertSame('/usr/local/bin/pg_dump', $this->probe()->callBinary('pg_dump'));
    }

    public function test_binary_ignores_empty_string_override(): void
    {
        config()->set('laranail.db-tools.backup.binaries.psql', '');

        self::assertSame('psql', $this->probe()->callBinary('psql'));
    }

    public function test_gzip_round_trip_preserves_the_dump_byte_for_byte(): void
    {
        $dir = sys_get_temp_dir().'/dbt-gz-'.uniqid();
        mkdir($dir, 0755, true);

        // Larger than the 256 KiB read chunk so the streaming loop runs more
        // than once — a truncation bug is invisible on a single-chunk file.
        $payload = str_repeat("INSERT INTO t VALUES ('row');\n", 20000);
        file_put_contents($dir.'/dump.sql', $payload);

        try {
            $this->probe()->callGzipCompress($dir.'/dump.sql', $dir.'/dump.sql.gz');

            self::assertFileExists($dir.'/dump.sql.gz');
            self::assertFileDoesNotExist($dir.'/dump.sql', 'The source is removed only after a verified compress.');

            $this->probe()->callGzipDecompress($dir.'/dump.sql.gz', $dir.'/restored.sql');

            self::assertSame($payload, (string) file_get_contents($dir.'/restored.sql'));
        } finally {
            array_map(unlink(...), glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }

    public function test_gzip_compress_keeps_the_source_when_it_cannot_write(): void
    {
        // gzwrite/gzclose returns were ignored and the source was unlinked
        // unconditionally, so a failed compression destroyed the only good copy
        // and reported success. Whatever the trigger, the source must survive.
        $dir = sys_get_temp_dir().'/dbt-gz-fail-'.uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/dump.sql', 'precious');

        try {
            $this->expectException(RuntimeException::class);

            // A directory is not a writable destination.
            mkdir($dir.'/blocked.gz', 0755, true);
            $this->probe()->callGzipCompress($dir.'/dump.sql', $dir.'/blocked.gz');
        } finally {
            self::assertFileExists($dir.'/dump.sql', 'A failed compression must not delete the source.');
            @rmdir($dir.'/blocked.gz');
            @unlink($dir.'/dump.sql');
            @rmdir($dir);
        }
    }

    public function test_gzip_decompress_rejects_a_truncated_archive(): void
    {
        // The exact artefact a failed compress leaves behind. gzread does not
        // report an error on a truncated stream — it simply stops — so the loop
        // exited via gzeof and the caller was handed a SHORT dump and a success,
        // which then gets replayed into the database.
        $dir = sys_get_temp_dir().'/dbt-gz-trunc-'.uniqid();
        mkdir($dir, 0755, true);

        $payload = str_repeat("INSERT INTO t VALUES ('row');\n", 20000);
        file_put_contents($dir.'/dump.sql', $payload);

        $this->probe()->callGzipCompress($dir.'/dump.sql', $dir.'/dump.sql.gz');

        $whole = (string) file_get_contents($dir.'/dump.sql.gz');
        file_put_contents($dir.'/truncated.gz', substr($whole, 0, (int) (strlen($whole) * 0.6)));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('truncated');

            $this->probe()->callGzipDecompress($dir.'/truncated.gz', $dir.'/restored.sql');
        } finally {
            array_map(unlink(...), glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }

    public function test_temp_files_are_created_private(): void
    {
        // A regression guard, not a fix: rename() preserves the mode, so the
        // suffixed path was already 0600 and the audit's "world-readable dump
        // in /tmp" finding did not reproduce. This pins the property so a
        // future change to how the path is produced cannot quietly widen it.
        $subject = new class
        {
            use ResolvesBackupOptions {
                makeTempFile as public;
            }
        };

        foreach (['', '.sql', '.sql.gz'] as $suffix) {
            $path = $subject->makeTempFile($suffix);

            try {
                self::assertFileExists($path);
                self::assertSame(
                    '0600',
                    substr(sprintf('%o', fileperms($path)), -4),
                    "Temp file with suffix '{$suffix}' must not be group- or world-readable.",
                );
            } finally {
                @unlink($path);
            }
        }
    }
}
