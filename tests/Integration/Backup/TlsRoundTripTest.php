<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Integration\Backup;

use Pdo\Mysql;
use RuntimeException;
use PHPUnit\Framework\Attributes\Group;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Backup\BackupManager;
use Simtabi\Laranail\DbTools\Tests\Integration\Backup\Concerns\RunsBackupRoundTrips;

/**
 * Backups against servers that refuse plaintext, as a managed provider's do.
 *
 * The `backups-tls` CI job starts MySQL with require_secure_transport=ON and
 * PostgreSQL with hostssl-only pg_hba, both presenting a certificate for
 * DB_TLS_HOST signed by DB_TLS_CA, and connects as restricted users. It runs
 * this group with --fail-on-skipped; everywhere else it skips.
 *
 * A round trip alone cannot prove the TLS mapping: mysql's client and libpq
 * both default to opportunistic TLS, so a dump that dropped every TLS flag
 * would still connect, encrypted but unverified. The second test in each
 * pair supplies the wrong CA. Only a dump that really verifies the server
 * (--ssl-mode=VERIFY_IDENTITY, PGSSLMODE=verify-full) refuses it.
 */
#[Group('backups-tls')]
final class TlsRoundTripTest extends TestCase
{
    use RunsBackupRoundTrips;

    public function test_mysql_round_trip_over_verified_tls(): void
    {
        $this->skipUnlessReady('mysqldump', 'mysql', 'MYSQL');
        $this->assertReachable('mysql_tls', $this->mysqlConfig($this->tls('CA')));

        $this->runRoundTrip('mysql_tls', '.sql');
    }

    public function test_mysql_dump_refuses_a_server_its_ca_did_not_sign(): void
    {
        $this->skipUnlessReady('mysqldump', 'mysql', 'MYSQL');
        $this->assertReachable('mysql_tls', $this->mysqlConfig($this->tls('CA')));

        config()->set('database.connections.mysql_wrong_ca', $this->mysqlConfig($this->tls('WRONG_CA')));

        $this->expectException(RuntimeException::class);
        (new BackupManager)->backup($this->tempPath('.sql'), 'mysql_wrong_ca');
    }

    public function test_postgres_round_trip_over_verified_tls(): void
    {
        $this->skipUnlessReady('pg_dump', 'pg_restore', 'PGSQL');
        $this->assertReachable('pgsql_tls', $this->pgsqlConfig($this->tls('CA')));

        $this->runRoundTrip('pgsql_tls', '.dump');
    }

    public function test_postgres_dump_refuses_a_server_its_ca_did_not_sign(): void
    {
        $this->skipUnlessReady('pg_dump', 'pg_restore', 'PGSQL');
        $this->assertReachable('pgsql_tls', $this->pgsqlConfig($this->tls('CA')));

        config()->set('database.connections.pgsql_wrong_ca', $this->pgsqlConfig($this->tls('WRONG_CA')));

        $this->expectException(RuntimeException::class);
        (new BackupManager)->backup($this->tempPath('.dump'), 'pgsql_wrong_ca');
    }

    private function skipUnlessReady(string $dump, string $restore, string $engine): void
    {
        if ($this->tls('HOST') === '' || $this->tls('CA') === '' || $this->tls('WRONG_CA') === '' || $this->tls("{$engine}_PORT") === '') {
            self::markTestSkipped("needs DB_TLS_HOST, DB_TLS_CA, DB_TLS_WRONG_CA and DB_TLS_{$engine}_PORT (the backups-tls CI job)");
        }

        if (! $this->binaryOnPath($dump) || ! $this->binaryOnPath($restore)) {
            self::markTestSkipped("{$dump} / {$restore} not on PATH.");
        }
    }

    /**
     * The good-CA connection must work, or the wrong-CA test would pass on a
     * server that refuses everyone.
     *
     * @param array<string, mixed> $config
     */
    private function assertReachable(string $name, array $config): void
    {
        self::assertTrue($this->serverReachable($name, $config), "PDO cannot reach [{$name}] over verified TLS.");
    }

    /**
     * @return array<string, mixed>
     */
    private function mysqlConfig(string $ca): array
    {
        return [
            ...$this->common('MYSQL'),
            'driver'  => 'mysql',
            'options' => [
                Mysql::ATTR_SSL_CA                 => $ca,
                Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pgsqlConfig(string $ca): array
    {
        return [
            ...$this->common('PGSQL'),
            'driver'      => 'pgsql',
            'search_path' => 'public',
            'sslmode'     => 'verify-full',
            'sslrootcert' => $ca,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function common(string $engine): array
    {
        return [
            'host'     => $this->tls('HOST'),
            'port'     => $this->tls("{$engine}_PORT"),
            'database' => $this->tls('DATABASE', 'db_tools_test'),
            'username' => $this->tls('USERNAME'),
            'password' => $this->tls('PASSWORD'),
            'prefix'   => '',
        ];
    }

    private function tls(string $variable, string $default = ''): string
    {
        $value = getenv("DB_TLS_{$variable}");

        return $value === false || $value === '' ? $default : $value;
    }

    private function tempPath(string $suffix): string
    {
        $path = self::uniqueTempPath('dbt-tls-backup-', $suffix);
        $this->tempFiles[] = $path;

        return $path;
    }
}
