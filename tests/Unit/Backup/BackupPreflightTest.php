<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Backup;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Backup\BackupManager;
use Simtabi\Laranail\DbTools\Backup\Enums\PreflightStatus;
use Simtabi\Laranail\DbTools\Backup\Drivers\MysqlBackupDriver;
use Simtabi\Laranail\DbTools\Backup\Drivers\SqliteBackupDriver;
use Simtabi\Laranail\DbTools\Backup\Drivers\PostgresBackupDriver;

/**
 * A backup's preflight finds a missing or too-old client before a backup is
 * attempted, so a caller (db-console's doctor) can report it while nothing
 * depends on it, rather than when a drop is refused. The client tools are
 * faked; nothing is dumped.
 */
final class BackupPreflightTest extends TestCase
{
    public function test_a_pg_dump_older_than_the_server_is_outdated(): void
    {
        $this->fakePostgres(client: 16, server: 18);

        $preflight = (new PostgresBackupDriver)->preflight($this->pgConfig());

        self::assertSame(PreflightStatus::Outdated, $preflight->status);
        self::assertSame(16, $preflight->clientVersion);
        self::assertSame(18, $preflight->serverVersion);
        self::assertStringContainsString('pg_dump 16 is older than the PostgreSQL 18 server', $preflight->message);
        self::assertFalse($preflight->isReady());
    }

    public function test_a_pg_dump_as_new_as_the_server_or_newer_is_ready(): void
    {
        foreach ([[18, 18], [18, 16]] as [$client, $server]) {
            $this->fakePostgres(client: $client, server: $server);

            $preflight = (new PostgresBackupDriver)->preflight($this->pgConfig());

            self::assertSame(PreflightStatus::Ready, $preflight->status, "pg_dump {$client} against {$server}");
            self::assertSame($server, $preflight->serverVersion);
        }
    }

    public function test_a_pg_dump_that_does_not_run_is_missing_and_the_server_is_not_asked(): void
    {
        Process::fake([
            '*--version*' => Process::result(errorOutput: 'pg_dump: command not found', exitCode: 127),
            '*'           => '',
        ]);

        $preflight = (new PostgresBackupDriver)->preflight($this->pgConfig());

        self::assertSame(PreflightStatus::Missing, $preflight->status);
        self::assertSame('pg_dump', $preflight->binary);
        self::assertStringContainsString('laranail.db-tools.backup.binaries.pg_dump', $preflight->message);
        Process::assertNotRan(fn (PendingProcess $p): bool => in_array('--command=SHOW server_version_num', (array) $p->command, true));
    }

    public function test_an_unreadable_server_version_is_ready_and_not_compared(): void
    {
        Process::fake([
            '*--version*'          => "pg_dump (PostgreSQL) 16.15 (Homebrew)\n",
            '*server_version_num*' => Process::result(errorOutput: 'connection refused', exitCode: 2),
            '*'                    => '',
        ]);

        $preflight = (new PostgresBackupDriver)->preflight($this->pgConfig());

        self::assertSame(PreflightStatus::Ready, $preflight->status);
        self::assertSame(16, $preflight->clientVersion);
        self::assertNull($preflight->serverVersion);
    }

    public function test_the_configured_binary_is_the_one_probed(): void
    {
        config()->set('laranail.db-tools.backup.binaries.pg_dump', '/opt/pg18/bin/pg_dump');
        $this->fakePostgres(client: 18, server: 18);

        $preflight = (new PostgresBackupDriver)->preflight($this->pgConfig());

        self::assertSame('/opt/pg18/bin/pg_dump', $preflight->binary);
        Process::assertRan(fn (PendingProcess $p): bool => ((array) $p->command)[0] === '/opt/pg18/bin/pg_dump');
    }

    public function test_mysqldump_is_ready_when_it_runs_and_reads_either_vendors_version(): void
    {
        $cases = [
            "mysqldump  Ver 8.0.44 for macos12.7 on arm64 (Source distribution)\n"           => 8,
            "mysqldump  Ver 10.19 Distrib 10.11.6-MariaDB, for debian-linux-gnu (aarch64)\n" => 10,
        ];

        foreach ($cases as $output => $major) {
            Process::fake(['*--version*' => $output, '*' => '']);

            $preflight = (new MysqlBackupDriver)->preflight($this->mysqlConfig());

            self::assertSame(PreflightStatus::Ready, $preflight->status);
            self::assertSame($major, $preflight->clientVersion);
            self::assertNull($preflight->serverVersion);
        }
    }

    public function test_a_mysqldump_that_does_not_run_is_missing(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'mysqldump: command not found', exitCode: 127)]);

        $preflight = (new MysqlBackupDriver)->preflight($this->mysqlConfig());

        self::assertSame(PreflightStatus::Missing, $preflight->status);
        self::assertStringContainsString('laranail.db-tools.backup.binaries.mysqldump', $preflight->message);
    }

    public function test_sqlite_needs_no_client(): void
    {
        self::assertTrue((new SqliteBackupDriver)->preflight(['driver' => 'sqlite', 'database' => ':memory:'])->isReady());
    }

    public function test_the_manager_preflights_a_named_connection(): void
    {
        config()->set('database.connections.preflight_pg', $this->pgConfig());
        $this->fakePostgres(client: 16, server: 18);

        $preflight = (new BackupManager)->preflight('preflight_pg');

        self::assertNotNull($preflight);
        self::assertSame(PreflightStatus::Outdated, $preflight->status);
    }

    public function test_the_manager_returns_null_for_a_driver_that_cannot_preflight(): void
    {
        config()->set('database.connections.preflight_fake', ['driver' => 'recording', 'database' => 'x']);
        $manager = (new BackupManager)->registerDriver(new RecordingBackupDriver(['recording']));

        self::assertNull($manager->preflight('preflight_fake'));
    }

    private function fakePostgres(int $client, int $server): void
    {
        Process::fake([
            '*--version*'          => "pg_dump (PostgreSQL) {$client}.4\n",
            '*server_version_num*' => $server * 10000 + 2 . "\n",
            '*'                    => '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pgConfig(): array
    {
        return [
            'driver'   => 'pgsql',
            'host'     => 'db.internal',
            'port'     => '5432',
            'database' => 'app',
            'username' => 'app',
            'password' => 'secret',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mysqlConfig(): array
    {
        return [
            'driver'   => 'mysql',
            'host'     => 'db.internal',
            'port'     => '3306',
            'database' => 'app',
            'username' => 'app',
            'password' => 'secret',
        ];
    }
}
