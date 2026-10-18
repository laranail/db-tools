<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Integration\Backup\Concerns;

use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Backup\BackupManager;

/**
 * A real backup and restore through BackupManager: a table, a row and a view
 * go out with the dump and must all come back. Shared by the plaintext and
 * the TLS round trips so both prove the same thing.
 */
trait RunsBackupRoundTrips
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

    protected function binaryOnPath(string $name): bool
    {
        $which = shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null');

        return is_string($which) && trim($which) !== '';
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function serverReachable(string $name, array $config): bool
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

    protected function runRoundTrip(string $connection, string $suffix): void
    {
        DB::connection($connection)->statement('DROP VIEW IF EXISTS rt_widget_names');
        Schema::connection($connection)->dropIfExists('rt_widgets');
        Schema::connection($connection)->create('rt_widgets', function ($t): void {
            $t->integer('id');
            $t->string('name');
        });

        DB::connection($connection)->table('rt_widgets')->insert([
            'id'   => 1,
            'name' => 'original-row',
        ]);
        DB::connection($connection)->statement('CREATE VIEW rt_widget_names AS SELECT name FROM rt_widgets');

        $backupPath = self::uniqueTempPath('dbt-server-backup-', $suffix);
        $this->tempFiles[] = $backupPath;

        $manager = new BackupManager;

        self::assertTrue($manager->backup($backupPath, $connection));
        self::assertFileExists($backupPath);

        DB::connection($connection)->statement('DROP VIEW rt_widget_names');
        DB::connection($connection)->table('rt_widgets')->delete();
        self::assertSame(0, DB::connection($connection)->table('rt_widgets')->count());

        self::assertTrue($manager->restore($backupPath, $connection));

        self::assertSame(1, DB::connection($connection)->table('rt_widgets')->count());
        self::assertSame(
            'original-row',
            DB::connection($connection)->table('rt_widgets')->where('id', 1)->value('name'),
        );
        self::assertSame(
            'original-row',
            DB::connection($connection)->table('rt_widget_names')->value('name'),
            'The view must come back too.',
        );

        DB::connection($connection)->statement('DROP VIEW IF EXISTS rt_widget_names');
        Schema::connection($connection)->dropIfExists('rt_widgets');
    }
}
