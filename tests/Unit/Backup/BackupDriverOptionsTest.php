<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Backup;

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
        };
    }

    public function test_gzip_disabled_by_default_and_toggled_by_config(): void
    {
        $probe = $this->probe();

        self::assertFalse($probe->callGzipEnabled());

        config()->set('db-tools.backup.gzip', true);

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

        config()->set('db-tools.backup.exclude', ['sessions', 'cache', '', 'jobs']);

        // Empty entries are dropped and the list is re-indexed.
        self::assertSame(['sessions', 'cache', 'jobs'], $probe->callExcludedTables());
    }

    public function test_excluded_tables_returns_empty_when_config_not_an_array(): void
    {
        config()->set('db-tools.backup.exclude', 'not-an-array');

        self::assertSame([], $this->probe()->callExcludedTables());
    }

    public function test_binary_falls_back_to_bare_name_without_override(): void
    {
        config()->set('db-tools.backup.binaries.mysqldump');

        self::assertSame('mysqldump', $this->probe()->callBinary('mysqldump'));
    }

    public function test_binary_honours_configured_absolute_path(): void
    {
        config()->set('db-tools.backup.binaries.pg_dump', '/usr/local/bin/pg_dump');

        self::assertSame('/usr/local/bin/pg_dump', $this->probe()->callBinary('pg_dump'));
    }

    public function test_binary_ignores_empty_string_override(): void
    {
        config()->set('db-tools.backup.binaries.psql', '');

        self::assertSame('psql', $this->probe()->callBinary('psql'));
    }
}
