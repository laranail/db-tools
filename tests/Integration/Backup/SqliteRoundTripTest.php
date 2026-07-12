<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Integration\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Backup\BackupManager;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class SqliteRoundTripTest extends TestCase
{
    private string $dbPath = '';

    private string $backupPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        // A real, file-based SQLite database (not :memory:) so the file-copy
        // backup path can actually read and write a file. SQLite's connector
        // requires the file to pre-exist, so create it before connecting.
        $this->dbPath = tempnam(sys_get_temp_dir(), 'dbt-sqlite-').'.sqlite';
        touch($this->dbPath);

        config()->set('database.connections.roundtrip', [
            'driver' => 'sqlite',
            'database' => $this->dbPath,
            'prefix' => '',
        ]);

        $this->seedDatabase();
    }

    protected function tearDown(): void
    {
        DB::purge('roundtrip');

        foreach ([$this->dbPath, $this->backupPath] as $file) {
            if ($file !== '' && is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    private function seedDatabase(): void
    {
        Schema::connection('roundtrip')->dropIfExists('widgets');
        Schema::connection('roundtrip')->create('widgets', function ($t): void {
            $t->integer('id');
            $t->string('name');
        });

        DB::connection('roundtrip')->table('widgets')->insert([
            'id' => 1,
            'name' => 'original-row',
        ]);
    }

    public function test_file_copy_backup_and_restore_round_trip(): void
    {
        $this->backupPath = tempnam(sys_get_temp_dir(), 'dbt-backup-').'.sqlite';

        $manager = new BackupManager;

        self::assertTrue($manager->backup($this->backupPath, 'roundtrip'));
        self::assertFileExists($this->backupPath);

        // Wipe the live database and confirm the row is gone.
        DB::connection('roundtrip')->table('widgets')->delete();
        self::assertSame(0, DB::connection('roundtrip')->table('widgets')->count());

        // Restoring the file-copy backup swaps the database file back; purge so
        // the connection reopens against the restored file.
        self::assertTrue($manager->restore($this->backupPath, 'roundtrip'));
        DB::purge('roundtrip');

        self::assertSame(1, DB::connection('roundtrip')->table('widgets')->count());
        self::assertSame(
            'original-row',
            DB::connection('roundtrip')->table('widgets')->where('id', 1)->value('name')
        );
    }

    public function test_gzip_backup_and_restore_round_trip(): void
    {
        config()->set('db-tools.backup.gzip', true);

        $this->backupPath = tempnam(sys_get_temp_dir(), 'dbt-backup-').'.sqlite.gz';

        $manager = new BackupManager;

        self::assertTrue($manager->backup($this->backupPath, 'roundtrip'));
        self::assertFileExists($this->backupPath);
        // The gzip stream should not be a verbatim copy of the source file.
        self::assertNotSame(
            file_get_contents($this->dbPath),
            file_get_contents($this->backupPath)
        );

        DB::connection('roundtrip')->table('widgets')->delete();
        self::assertSame(0, DB::connection('roundtrip')->table('widgets')->count());

        self::assertTrue($manager->restore($this->backupPath, 'roundtrip'));
        DB::purge('roundtrip');

        self::assertSame(1, DB::connection('roundtrip')->table('widgets')->count());
        self::assertSame(
            'original-row',
            DB::connection('roundtrip')->table('widgets')->where('id', 1)->value('name')
        );
    }
}
