<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Integration\Backup;

use PHPUnit\Framework\Attributes\Group;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Tests\Integration\Backup\Concerns\RunsBackupRoundTrips;

/**
 * Real MySQL and PostgreSQL backup/restore round-trips.
 *
 * These require both a reachable server AND the CLI binaries on PATH. On any
 * machine without them (the common case, incl. CI without service containers)
 * each test SKIPS cleanly — it must never fail for an unavailable dependency.
 *
 * CI runs the `backups` group against real servers with --fail-on-skipped, so
 * there a skip is a failure: the env vars below are set by the `drivers` job.
 *
 * The connection is described by `url` alone, with `host` pointing nowhere,
 * as a managed provider's DATABASE_URL would be. A dump built from the raw
 * keys cannot reach the server, so this also proves `url` wins. The view puts
 * a DEFINER clause (MySQL) and an owned object (PostgreSQL) through the
 * restore path.
 */
#[Group('backups')]
final class MysqlPostgresRoundTripTest extends TestCase
{
    use RunsBackupRoundTrips;

    public function test_mysql_round_trip_or_skip(): void
    {
        if (! $this->binaryOnPath('mysqldump') || ! $this->binaryOnPath('mysql')) {
            self::markTestSkipped('mysqldump / mysql client not on PATH.');
        }

        $config = [
            'driver' => 'mysql',
            'host'   => 'unreachable.invalid',
            'url'    => $this->url('mysql', 'DB_MYSQL', '3306', 'root'),
            'prefix' => '',
        ];

        if (! $this->serverReachable('mysql_rt', $config)) {
            self::markTestSkipped('No reachable MySQL server.');
        }

        $this->runRoundTrip('mysql_rt', '.sql');
    }

    public function test_postgres_round_trip_or_skip(): void
    {
        if (! $this->binaryOnPath('pg_dump') || ! $this->binaryOnPath('pg_restore')) {
            self::markTestSkipped('pg_dump / pg_restore not on PATH.');
        }

        $config = [
            'driver'      => 'pgsql',
            'host'        => 'unreachable.invalid',
            'url'         => $this->url('postgresql', 'DB_PGSQL', '5432', 'postgres'),
            'prefix'      => '',
            'search_path' => 'public',
        ];

        if (! $this->serverReachable('pgsql_rt', $config)) {
            self::markTestSkipped('No reachable PostgreSQL server.');
        }

        // Postgres uses a custom-format dump; a non-".sql" path triggers
        // pg_dump/pg_restore rather than the psql text path.
        $this->runRoundTrip('pgsql_rt', '.dump');
    }

    private function url(string $scheme, string $prefix, string $port, string $user): string
    {
        return sprintf(
            '%s://%s:%s@%s:%s/%s',
            $scheme,
            rawurlencode((string) env("{$prefix}_USERNAME", $user)),
            rawurlencode((string) env("{$prefix}_PASSWORD", '')),
            env("{$prefix}_HOST", '127.0.0.1'),
            env("{$prefix}_PORT", $port),
            env("{$prefix}_DATABASE", 'db_tools_test'),
        );
    }
}
