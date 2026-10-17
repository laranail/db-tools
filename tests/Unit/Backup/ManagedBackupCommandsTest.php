<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Backup;

use RuntimeException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Backup\BackupManager;
use Simtabi\Laranail\DbTools\Backup\Drivers\MysqlBackupDriver;
use Simtabi\Laranail\DbTools\Backup\Drivers\PostgresBackupDriver;

/**
 * What the backup drivers hand to mysqldump / mysql / pg_dump / pg_restore.
 *
 * A managed cluster (DigitalOcean, RDS, …) is reached through a connection URL,
 * requires TLS, runs GTID, refuses SUPER and gives the app a non-superuser
 * owner. Every assertion here is a flag or variable whose absence made a dump
 * hit the wrong server, skip certificate checks, or produce a restore that a
 * managed server rejects. The commands are faked; the round trip against real
 * servers is MysqlPostgresRoundTripTest.
 */
final class ManagedBackupCommandsTest extends TestCase
{
    private const string MYSQL_HELP = "  --set-gtid-purged=name\n  --ssl-mode=name\n  --ssl-ca=name\n";

    private const string MARIADB_HELP = "  --ssl\n  --ssl-ca=name\n  --ssl-verify-server-cert\n";

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------- url

    public function test_mysql_backup_dumps_the_server_named_by_url_not_host(): void
    {
        config()->set('database.connections.managed_mysql', [
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'port'     => '3306',
            'database' => 'local',
            'username' => 'root',
            'password' => 'local-secret',
            'url'      => 'mysql://doadmin:s3cret@db-mysql.example.com:25060/defaultdb?ssl-mode=REQUIRED',
        ]);
        $this->fakeProcesses(self::MYSQL_HELP);

        (new BackupManager)->backup($this->tempPath('.sql'), 'managed_mysql');

        $dump = $this->ranCommand('mysqldump', exclude: '--help');

        self::assertContains('--host=db-mysql.example.com', $dump);
        self::assertContains('--port=25060', $dump);
        self::assertContains('--user=doadmin', $dump);
        self::assertContains('defaultdb', $dump);
        self::assertContains('--ssl-mode=REQUIRED', $dump);
        self::assertNotContains('--host=127.0.0.1', $dump);
        self::assertNotContains('local', $dump);
    }

    public function test_url_scheme_selects_the_backup_driver_as_it_does_for_pdo(): void
    {
        config()->set('database.connections.managed_pg', [
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'database' => 'local',
            'username' => 'root',
            'password' => 'local-secret',
            'url'      => 'postgresql://doadmin:s3cret@db-pg.example.com:25060/defaultdb?sslmode=require',
        ]);
        $this->fakeProcesses(pgClient: 17, pgServer: 17);

        (new BackupManager)->backup($this->tempPath('.dump'), 'managed_pg');

        $dump = $this->ranCommand('pg_dump', exclude: '--version');

        self::assertContains('--host=db-pg.example.com', $dump);
        self::assertContains('--port=25060', $dump);
        self::assertContains('--dbname=defaultdb', $dump);
        Process::assertNotRan(fn (PendingProcess $p): bool => $this->binaryOf($p) === 'mysqldump');
    }

    public function test_url_wins_on_restore_too(): void
    {
        config()->set('database.connections.managed_mysql', [
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'database' => 'local',
            'username' => 'root',
            'password' => 'local-secret',
            'url'      => 'mysql://doadmin:s3cret@db-mysql.example.com:25060/defaultdb',
        ]);
        $this->fakeProcesses(self::MYSQL_HELP);
        $dump = $this->tempPath('.sql');
        file_put_contents($dump, "SELECT 1;\n");

        (new BackupManager)->restore($dump, 'managed_mysql');

        $restore = $this->ranCommand('mysql', exclude: '--help');

        self::assertContains('--host=db-mysql.example.com', $restore);
        self::assertContains('defaultdb', $restore);
    }

    // -------------------------------------------------------------- mysql

    public function test_mysqldump_takes_a_consistent_managed_safe_dump(): void
    {
        $this->fakeProcesses(self::MYSQL_HELP);

        (new MysqlBackupDriver)->backup($this->mysqlConfig(), $this->tempPath('.sql'));

        $dump = $this->ranCommand('mysqldump', exclude: '--help');

        foreach (['--single-transaction', '--no-tablespaces', '--triggers', '--set-gtid-purged=OFF'] as $flag) {
            self::assertContains($flag, $dump, "mysqldump must be run with {$flag}");
        }

        // Routines are opt-in: a non-SUPER user on a binlogged server cannot
        // restore a stored function (1418), and mysqldump leaves them out too.
        self::assertNotContains('--routines', $dump);
        self::assertNotContains('--events', $dump);
    }

    public function test_routines_and_events_are_dumped_when_enabled(): void
    {
        config()->set('laranail.db-tools.backup.mysql.routines', true);
        $this->fakeProcesses(self::MYSQL_HELP);

        (new MysqlBackupDriver)->backup($this->mysqlConfig(), $this->tempPath('.sql'));

        $dump = $this->ranCommand('mysqldump', exclude: '--help');

        self::assertContains('--routines', $dump);
        self::assertContains('--events', $dump);
    }

    public function test_a_failed_help_probe_fails_instead_of_guessing_the_client(): void
    {
        Process::fake([
            '*--help*' => Process::result(errorOutput: 'mysqldump: unknown variable in ~/.my.cnf', exitCode: 7),
            '*'        => '',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown variable in ~/.my.cnf');

        (new MysqlBackupDriver)->backup($this->mysqlConfig(), $this->tempPath('.sql'));
    }

    public function test_set_gtid_purged_is_only_passed_to_a_client_that_knows_it(): void
    {
        // MariaDB's mysqldump has no --set-gtid-purged and exits on an unknown option.
        $this->fakeProcesses(self::MARIADB_HELP);

        (new MysqlBackupDriver)->backup($this->mysqlConfig(), $this->tempPath('.sql'));

        $dump = $this->ranCommand('mysqldump', exclude: '--help');

        self::assertEmpty(array_filter($dump, static fn (string $a): bool => str_starts_with($a, '--set-gtid-purged')));
        self::assertContains('--single-transaction', $dump);
    }

    public function test_password_goes_through_a_private_defaults_file_that_is_removed(): void
    {
        $seen = [];

        Process::fake([
            '*--help*' => self::MYSQL_HELP,
            '*'        => function (PendingProcess $process) use (&$seen): string {
                $file = substr((string) $process->command[1], strlen('--defaults-extra-file='));
                $seen = [
                    'first'   => $process->command[1],
                    'file'    => $file,
                    'mode'    => is_file($file) ? fileperms($file) & 0777 : null,
                    'content' => is_file($file) ? (string) file_get_contents($file) : null,
                    'env'     => $process->environment,
                ];

                return '';
            },
        ]);

        (new MysqlBackupDriver)->backup($this->mysqlConfig(['password' => 'p"a\\ss#word']), $this->tempPath('.sql'));

        self::assertStringStartsWith('--defaults-extra-file=', $seen['first'], 'The defaults file must be the first option or the client ignores it.');
        self::assertSame(0600, $seen['mode']);
        self::assertSame("[client]\npassword=\"p\\\"a\\\\ss#word\"\n", $seen['content']);
        self::assertArrayNotHasKey('MYSQL_PWD', $seen['env']);
        self::assertFileDoesNotExist($seen['file']);
    }

    public function test_defaults_file_is_removed_when_the_dump_fails(): void
    {
        $file = null;
        $first = null;

        Process::fake([
            '*--help*' => self::MYSQL_HELP,
            '*'        => function (PendingProcess $process) use (&$file, &$first) {
                $first = (string) $process->command[1];
                $file = substr($first, strlen('--defaults-extra-file='));
                self::assertFileExists($file, 'The defaults file must exist while the client runs.');

                return Process::result(errorOutput: 'Access denied', exitCode: 2);
            },
        ]);

        try {
            (new MysqlBackupDriver)->backup($this->mysqlConfig(), $this->tempPath('.sql'));
            self::fail('A failed mysqldump must throw.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Access denied', $e->getMessage());
        }

        self::assertNotNull($first);
        self::assertStringStartsWith('--defaults-extra-file=', $first);
        self::assertFileDoesNotExist($file);
    }

    public function test_pdo_ssl_options_become_mysql_client_tls_flags(): void
    {
        $this->fakeProcesses(self::MYSQL_HELP);

        (new MysqlBackupDriver)->backup($this->mysqlConfig(['options' => [
            1009 => '/etc/ssl/do-ca.crt',    // Pdo\Mysql::ATTR_SSL_CA
            1008 => '/etc/ssl/client.crt',   // Pdo\Mysql::ATTR_SSL_CERT
            1007 => '/etc/ssl/client.key',   // Pdo\Mysql::ATTR_SSL_KEY
        ]]), $this->tempPath('.sql'));

        $dump = $this->ranCommand('mysqldump', exclude: '--help');

        self::assertContains('--ssl-mode=VERIFY_IDENTITY', $dump);
        self::assertContains('--ssl-ca=/etc/ssl/do-ca.crt', $dump);
        self::assertContains('--ssl-cert=/etc/ssl/client.crt', $dump);
        self::assertContains('--ssl-key=/etc/ssl/client.key', $dump);
    }

    public function test_disabled_server_cert_verification_requires_tls_without_verifying(): void
    {
        $this->fakeProcesses(self::MYSQL_HELP);

        (new MysqlBackupDriver)->backup($this->mysqlConfig(['options' => [
            1009 => '/etc/ssl/do-ca.crt',
            1014 => false,                   // Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT
        ]]), $this->tempPath('.sql'));

        $dump = $this->ranCommand('mysqldump', exclude: '--help');

        self::assertContains('--ssl-mode=REQUIRED', $dump);
        self::assertNotContains('--ssl-ca=/etc/ssl/do-ca.crt', $dump);
    }

    public function test_an_explicit_required_mode_never_hands_the_client_a_ca(): void
    {
        $this->fakeProcesses(self::MYSQL_HELP);

        // The shape a provider URL produces: ?ssl-mode=REQUIRED beside a CA.
        (new MysqlBackupDriver)->backup($this->mysqlConfig([
            'ssl-mode' => 'REQUIRED',
            'options'  => [1009 => '/etc/ssl/do-ca.crt', 1014 => false],
        ]), $this->tempPath('.sql'));

        $dump = $this->ranCommand('mysqldump', exclude: '--help');

        self::assertContains('--ssl-mode=REQUIRED', $dump);
        self::assertNotContains('--ssl-ca=/etc/ssl/do-ca.crt', $dump);
    }

    public function test_mariadb_required_mode_encrypts_without_verifying(): void
    {
        $this->fakeProcesses(self::MARIADB_HELP);

        (new MysqlBackupDriver)->backup($this->mysqlConfig(['ssl-mode' => 'REQUIRED']), $this->tempPath('.sql'));

        $dump = $this->ranCommand('mysqldump', exclude: '--help');

        // MariaDB 11.4+ verifies by default, which REQUIRED must not do.
        self::assertContains('--ssl', $dump);
        self::assertContains('--skip-ssl-verify-server-cert', $dump);
        self::assertNotContains('--ssl-verify-server-cert', $dump);
    }

    public function test_mariadb_client_gets_its_own_tls_flags(): void
    {
        $this->fakeProcesses(self::MARIADB_HELP);

        (new MysqlBackupDriver)->backup($this->mysqlConfig(['options' => [1009 => '/etc/ssl/ca.crt']]), $this->tempPath('.sql'));

        $dump = $this->ranCommand('mysqldump', exclude: '--help');

        self::assertContains('--ssl', $dump);
        self::assertContains('--ssl-verify-server-cert', $dump);
        self::assertContains('--ssl-ca=/etc/ssl/ca.crt', $dump);
        self::assertEmpty(array_filter($dump, static fn (string $a): bool => str_starts_with($a, '--ssl-mode')));
    }

    public function test_no_tls_configuration_passes_no_tls_flags(): void
    {
        $this->fakeProcesses(self::MYSQL_HELP);

        (new MysqlBackupDriver)->backup($this->mysqlConfig(), $this->tempPath('.sql'));

        $dump = $this->ranCommand('mysqldump', exclude: '--help');

        self::assertEmpty(array_filter($dump, static fn (string $a): bool => str_starts_with($a, '--ssl')));
    }

    public function test_an_unknown_ssl_mode_fails_closed(): void
    {
        $this->fakeProcesses(self::MYSQL_HELP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ssl-mode');

        (new MysqlBackupDriver)->backup($this->mysqlConfig(['sslmode' => 'verify-fulll']), $this->tempPath('.sql'));
    }

    public function test_mysql_restore_streams_the_dump_and_strips_definers(): void
    {
        $restored = null;
        $streamed = null;

        Process::fake([
            '*--help*' => self::MYSQL_HELP,
            '*'        => function (PendingProcess $process) use (&$restored, &$streamed): string {
                // Drained here: the driver closes the file once the client exits.
                $streamed = is_iterable($process->input) && ! is_string($process->input);
                $restored = $streamed ? implode('', iterator_to_array($process->input, false)) : (string) $process->input;

                return '';
            },
        ]);

        $dump = $this->tempPath('.sql');
        file_put_contents($dump, implode("\n", [
            'CREATE DEFINER=`doadmin`@`%` PROCEDURE `p`() SELECT 1;',
            '/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */',
            '/*!50017 DEFINER=`root`@`%`*/ /*!50003 TRIGGER `t` BEFORE INSERT ON `w` FOR EACH ROW SET @x = 1 */;',
            "INSERT INTO `notes` VALUES (1,'DEFINER=`kept`@`data`');",
            '',
        ]));

        (new MysqlBackupDriver)->restore($this->mysqlConfig(), $dump);

        self::assertTrue($streamed, 'The dump must be streamed, not read into a string.');

        $sql = (string) $restored;

        self::assertStringContainsString('CREATE  PROCEDURE `p`() SELECT 1;', $sql);
        self::assertStringContainsString('/*!50013  SQL SECURITY DEFINER */', $sql);
        self::assertStringContainsString('/*!50017 */ /*!50003 TRIGGER', $sql);
        self::assertStringContainsString("INSERT INTO `notes` VALUES (1,'DEFINER=`kept`@`data`');", $sql, 'Row data must never be rewritten.');
        self::assertStringNotContainsString('DEFINER=`root`', $sql);
        self::assertStringNotContainsString('DEFINER=`doadmin`', $sql);
    }

    public function test_definers_are_kept_when_stripping_is_switched_off(): void
    {
        config()->set('laranail.db-tools.backup.mysql.strip_definers', false);
        $restored = null;

        Process::fake([
            '*--help*' => self::MYSQL_HELP,
            '*'        => function (PendingProcess $process) use (&$restored): string {
                $restored = implode('', iterator_to_array($process->input, false));

                return '';
            },
        ]);

        $dump = $this->tempPath('.sql');
        file_put_contents($dump, "CREATE DEFINER=`reporting`@`%` VIEW `v` AS SELECT 1;\n");

        (new MysqlBackupDriver)->restore($this->mysqlConfig(), $dump);

        self::assertStringContainsString('DEFINER=`reporting`@`%`', (string) $restored);
    }

    public function test_mysql_restore_uses_the_defaults_file_and_tls(): void
    {
        $this->fakeProcesses(self::MYSQL_HELP);
        $dump = $this->tempPath('.sql');
        file_put_contents($dump, "SELECT 1;\n");

        (new MysqlBackupDriver)->restore($this->mysqlConfig(['options' => [1009 => '/etc/ssl/ca.crt']]), $dump);

        $restore = $this->ranCommand('mysql', exclude: '--help');

        self::assertStringStartsWith('--defaults-extra-file=', $restore[1]);
        self::assertContains('--ssl-mode=VERIFY_IDENTITY', $restore);
        Process::assertNotRan(fn (PendingProcess $p): bool => array_key_exists('MYSQL_PWD', $p->environment));
    }

    // ----------------------------------------------------------- postgres

    public function test_postgres_tls_settings_reach_libpq_through_the_environment(): void
    {
        $this->fakeProcesses(pgClient: 17, pgServer: 17);

        (new PostgresBackupDriver)->backup($this->pgConfig([
            'sslmode'     => 'verify-full',
            'sslrootcert' => '/etc/ssl/do-ca.crt',
            'sslcert'     => '/etc/ssl/client.crt',
            'sslkey'      => '/etc/ssl/client.key',
        ]), $this->tempPath('.dump'));

        Process::assertRan(function (PendingProcess $p): bool {
            if ($this->binaryOf($p) !== 'pg_dump' || in_array('--version', (array) $p->command, true)) {
                return false;
            }

            return ($p->environment['PGSSLMODE'] ?? null) === 'verify-full'
                && ($p->environment['PGSSLROOTCERT'] ?? null) === '/etc/ssl/do-ca.crt'
                && ($p->environment['PGSSLCERT'] ?? null) === '/etc/ssl/client.crt'
                && ($p->environment['PGSSLKEY'] ?? null) === '/etc/ssl/client.key'
                && ($p->environment['PGPASSWORD'] ?? null) === 'secret';
        });
    }

    public function test_pg_dump_keeps_ownership_and_grants_in_the_archive(): void
    {
        $this->fakeProcesses(pgClient: 17, pgServer: 17);

        (new PostgresBackupDriver)->backup($this->pgConfig(), $this->tempPath('.dump'));

        $dump = $this->ranCommand('pg_dump', exclude: '--version');

        // Restore leaves them out; dropping them from the archive too would
        // lose them for anyone restoring with another tool.
        self::assertNotContains('--no-owner', $dump);
        self::assertNotContains('--no-privileges', $dump);
    }

    public function test_pg_restore_is_atomic_and_owner_agnostic(): void
    {
        $this->fakeProcesses(pgClient: 17, pgServer: 17);
        $dump = $this->tempPath('.dump');
        file_put_contents($dump, 'PGDMP');

        (new PostgresBackupDriver)->restore($this->pgConfig(), $dump);

        $restore = $this->ranCommand('pg_restore', exclude: '--version');

        foreach (['--no-owner', '--no-privileges', '--single-transaction', '--exit-on-error'] as $flag) {
            self::assertContains($flag, $restore, "pg_restore must be run with {$flag}");
        }
    }

    public function test_an_older_pg_dump_than_the_server_fails_before_dumping(): void
    {
        $this->fakeProcesses(pgClient: 15, pgServer: 18);

        try {
            (new PostgresBackupDriver)->backup($this->pgConfig(), $this->tempPath('.dump'));
            self::fail('pg_dump 15 against a PostgreSQL 18 server must be refused.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('pg_dump 15', $e->getMessage());
            self::assertStringContainsString('PostgreSQL 18', $e->getMessage());
        }

        Process::assertNotRan(fn (PendingProcess $p): bool => $this->binaryOf($p) === 'pg_dump'
            && ! in_array('--version', (array) $p->command, true));
    }

    public function test_pg_restore_is_not_held_to_the_server_version(): void
    {
        // A pg_dump 15 archive restored into a PostgreSQL 17 cluster with
        // pg_restore 15 works; only the archive's version limits pg_restore.
        $this->fakeProcesses(pgClient: 15, pgServer: 17);
        $dump = $this->tempPath('.dump');
        file_put_contents($dump, 'PGDMP');

        self::assertTrue((new PostgresBackupDriver)->restore($this->pgConfig(), $dump));
    }

    public function test_an_archive_newer_than_pg_restore_says_so(): void
    {
        Process::fake([
            '*' => Process::result(errorOutput: 'pg_restore: error: unsupported version (1.16) in file header', exitCode: 1),
        ]);
        $dump = $this->tempPath('.dump');
        file_put_contents($dump, 'PGDMP');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('written by a newer pg_dump than this pg_restore');

        (new PostgresBackupDriver)->restore($this->pgConfig(), $dump);
    }

    public function test_a_newer_client_than_the_server_is_fine(): void
    {
        $this->fakeProcesses(pgClient: 18, pgServer: 16);

        self::assertTrue((new PostgresBackupDriver)->backup($this->pgConfig(), $this->tempPath('.dump')));
    }

    // ---------------------------------------------------------- timeouts

    public function test_dumps_and_restores_outlive_laravels_sixty_second_default(): void
    {
        $this->fakeProcesses(self::MYSQL_HELP, pgClient: 17, pgServer: 17);

        (new MysqlBackupDriver)->backup($this->mysqlConfig(), $this->tempPath('.sql'));
        (new PostgresBackupDriver)->backup($this->pgConfig(), $this->tempPath('.dump'));

        foreach (['mysqldump', 'pg_dump'] as $binary) {
            Process::assertRan(fn (PendingProcess $p): bool => $this->binaryOf($p) === $binary
                && ! in_array('--version', (array) $p->command, true)
                && ! in_array('--help', (array) $p->command, true)
                && $p->timeout === 3600);
        }
    }

    public function test_a_zero_timeout_lifts_the_limit(): void
    {
        config()->set('laranail.db-tools.backup.timeout', 0);
        $this->fakeProcesses(pgClient: 17, pgServer: 17);

        (new PostgresBackupDriver)->backup($this->pgConfig(), $this->tempPath('.dump'));

        Process::assertRan(fn (PendingProcess $p): bool => $this->binaryOf($p) === 'pg_dump'
            && ! in_array('--version', (array) $p->command, true)
            && $p->timeout === null);
    }

    public function test_a_plain_sql_restore_stops_at_the_first_failed_statement(): void
    {
        $this->fakeProcesses(pgClient: 17, pgServer: 17);
        $dump = $this->tempPath('.sql');
        file_put_contents($dump, "SELECT 1;\n");

        (new PostgresBackupDriver)->restore($this->pgConfig(), $dump);

        self::assertContains('--set=ON_ERROR_STOP=1', $this->ranCommand('psql', exclude: '--tuples-only'));
    }

    // ------------------------------------------------------------ helpers

    private function fakeProcesses(string $mysqlHelp = '', int $pgClient = 17, int $pgServer = 17): void
    {
        Process::fake([
            '*--help*'             => $mysqlHelp,
            '*--version*'          => "pg_dump (PostgreSQL) {$pgClient}.4 (Debian {$pgClient}.4-1.pgdg120+1)\n",
            '*server_version_num*' => $pgServer * 10000 + 2 . "\n",
            '*'                    => '',
        ]);
    }

    /**
     * The argv of the first run whose binary is $binary and which does not
     * carry $exclude (so a capability probe is not mistaken for the real run).
     *
     * @return array<int, string>
     */
    private function ranCommand(string $binary, string $exclude): array
    {
        $found = null;

        Process::assertRan(function (PendingProcess $p) use ($binary, $exclude, &$found): bool {
            $command = (array) $p->command;

            if ($found !== null || $this->binaryOf($p) !== $binary || in_array($exclude, $command, true)) {
                return false;
            }

            $found = array_map(strval(...), $command);

            return true;
        });

        return $found ?? [];
    }

    private function binaryOf(PendingProcess $process): string
    {
        $command = (array) $process->command;

        return basename((string) ($command[0] ?? ''));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function mysqlConfig(array $overrides = []): array
    {
        return array_replace([
            'driver'   => 'mysql',
            'host'     => 'db.internal',
            'port'     => '3306',
            'database' => 'app',
            'username' => 'app',
            'password' => 'secret',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function pgConfig(array $overrides = []): array
    {
        return array_replace([
            'driver'   => 'pgsql',
            'host'     => 'db.internal',
            'port'     => '5432',
            'database' => 'app',
            'username' => 'app',
            'password' => 'secret',
        ], $overrides);
    }

    private function tempPath(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/dbt-managed-' . bin2hex(random_bytes(6)) . $suffix;
        $this->tempFiles[] = $path;

        return $path;
    }
}
