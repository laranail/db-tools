<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Integration\Backup;

use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Backup\BackupManager;

/**
 * Real MySQL and PostgreSQL backup/restore round-trips.
 *
 * These require both a reachable server AND the CLI binaries on PATH. On any
 * machine without them (the common case, incl. CI without service containers)
 * each test SKIPS cleanly — it must never fail for an unavailable dependency.
 */
final class MysqlPostgresRoundTripTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_mysql_round_trip_or_skip(): void
    {
        if (! $this->binaryOnPath('mysqldump') || ! $this->binaryOnPath('mysql')) {
            self::markTestSkipped('mysqldump / mysql client not on PATH.');
        }

        $config = [
            'driver'   => 'mysql',
            'host'     => env('DB_MYSQL_HOST', '127.0.0.1'),
            'port'     => env('DB_MYSQL_PORT', '3306'),
            'database' => env('DB_MYSQL_DATABASE', 'db_tools_test'),
            'username' => env('DB_MYSQL_USERNAME', 'root'),
            'password' => env('DB_MYSQL_PASSWORD', ''),
            'prefix'   => '',
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
            'host'        => env('DB_PGSQL_HOST', '127.0.0.1'),
            'port'        => env('DB_PGSQL_PORT', '5432'),
            'database'    => env('DB_PGSQL_DATABASE', 'db_tools_test'),
            'username'    => env('DB_PGSQL_USERNAME', 'postgres'),
            'password'    => env('DB_PGSQL_PASSWORD', ''),
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

    private function binaryOnPath(string $name): bool
    {
        $which = shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null');

        return is_string($which) && trim($which) !== '';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function serverReachable(string $name, array $config): bool
    {
        config()->set("database.connections.{$name}", $config);
        DB::purge($name);

        try {
            DB::connection($name)->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function runRoundTrip(string $connection, string $suffix): void
    {
        Schema::connection($connection)->dropIfExists('rt_widgets');
        Schema::connection($connection)->create('rt_widgets', function ($t): void {
            $t->integer('id');
            $t->string('name');
        });

        DB::connection($connection)->table('rt_widgets')->insert([
            'id'   => 1,
            'name' => 'original-row',
        ]);

        $backupPath = tempnam(sys_get_temp_dir(), 'dbt-server-backup-') . $suffix;
        $this->tempFiles[] = $backupPath;

        $manager = new BackupManager;

        self::assertTrue($manager->backup($backupPath, $connection));
        self::assertFileExists($backupPath);

        DB::connection($connection)->table('rt_widgets')->delete();
        self::assertSame(0, DB::connection($connection)->table('rt_widgets')->count());

        self::assertTrue($manager->restore($backupPath, $connection));

        self::assertSame(1, DB::connection($connection)->table('rt_widgets')->count());
        self::assertSame(
            'original-row',
            DB::connection($connection)->table('rt_widgets')->where('id', 1)->value('name'),
        );

        Schema::connection($connection)->dropIfExists('rt_widgets');
    }
}
