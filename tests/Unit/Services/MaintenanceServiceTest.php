<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Services;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Psr\Log\NullLogger;
use Simtabi\Laranail\DbTools\Services\MaintenanceService;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class MaintenanceServiceTest extends TestCase
{
    private string $base;

    private MaintenanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/dbtools-maint-'.uniqid();
        File::makeDirectory($this->base, 0755, true);

        $this->service = new MaintenanceService(new NullLogger, $this->base);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);

        parent::tearDown();
    }

    public function test_clear_cache_flushes_and_deletes_cache_files(): void
    {
        Event::fake();

        File::makeDirectory($this->base.'/storage/framework/cache', 0755, true);
        File::put($this->base.'/storage/framework/cache/facade-abc.php', '<?php');
        File::put($this->base.'/storage/framework/cache/keep.txt', 'keep');

        File::makeDirectory($this->base.'/bootstrap/cache', 0755, true);
        File::put($this->base.'/bootstrap/cache/services.php', '<?php');

        self::assertTrue($this->service->clearCache());

        self::assertFalse(File::exists($this->base.'/storage/framework/cache/facade-abc.php'));
        self::assertTrue(File::exists($this->base.'/storage/framework/cache/keep.txt'));
        self::assertFalse(File::exists($this->base.'/bootstrap/cache/services.php'));

        Event::assertDispatched('cache:clearing');
        Event::assertDispatched('cache:cleared');
    }

    public function test_clear_log_files_deletes_logs_but_preserves_gitignore(): void
    {
        File::makeDirectory($this->base.'/storage/logs', 0755, true);
        File::put($this->base.'/storage/logs/laravel.log', 'log');
        File::put($this->base.'/storage/logs/.gitignore', '*');

        self::assertTrue($this->service->clearLogFiles());

        self::assertFalse(File::exists($this->base.'/storage/logs/laravel.log'));
        self::assertTrue(File::exists($this->base.'/storage/logs/.gitignore'));
    }

    public function test_delete_storage_symlink_removes_it_when_present(): void
    {
        File::makeDirectory($this->base.'/public', 0755, true);
        File::put($this->base.'/public/storage', 'link-target');

        self::assertTrue($this->service->deleteStorageSymlink());
        self::assertFalse(File::exists($this->base.'/public/storage'));
    }

    public function test_delete_storage_symlink_returns_false_when_absent(): void
    {
        self::assertFalse($this->service->deleteStorageSymlink());
    }

    public function test_clear_log_files_does_not_follow_a_symlink_out_of_storage(): void
    {
        Event::fake();

        // The loops deleted $file->getRealPath() — the resolved TARGET — so a
        // symlink inside storage/logs pointing anywhere the process can unlink
        // took that target with it. getRealPath() is precisely the call that
        // defeats containment.
        $outside = $this->base.'/outside-the-root.conf';
        File::put($outside, 'must survive');

        File::makeDirectory($this->base.'/storage/logs', 0755, true);
        symlink($outside, $this->base.'/storage/logs/escape.log');

        self::assertTrue($this->service->clearLogFiles());

        self::assertFileExists($outside, 'A symlink inside storage must never delete its target outside.');
        self::assertFalse(is_link($this->base.'/storage/logs/escape.log'), 'The link itself should be removed.');
    }

    public function test_clear_log_files_survives_a_dangling_symlink(): void
    {
        Event::fake();

        // getRealPath() returns false for a dangling link; under strict_types
        // preg_match(pattern, false) raises a TypeError, which is an Error and
        // therefore not caught by `catch (Exception)`.
        File::makeDirectory($this->base.'/storage/logs', 0755, true);
        symlink($this->base.'/gone.log', $this->base.'/storage/logs/dangling.log');

        self::assertTrue($this->service->clearLogFiles());
    }

    public function test_clear_cache_matches_the_facade_prefix_on_the_basename(): void
    {
        Event::fake();

        // The pattern was tested against the whole path, so every .php file in
        // the cache directory matched on a project living under, say,
        // /srv/facade-demo/.
        // Reproduce the real condition: a project whose PATH contains
        // "facade-". Matching against the full path then matches every .php.
        $base = sys_get_temp_dir().'/facade-demo-'.uniqid();
        File::makeDirectory($base.'/storage/framework/cache', 0755, true);
        File::put($base.'/storage/framework/cache/facade-abc.php', '<?php');
        File::put($base.'/storage/framework/cache/keep-me.php', '<?php');

        $service = new MaintenanceService(new NullLogger, $base);

        try {
            self::assertTrue($service->clearCache());

            self::assertFileDoesNotExist($base.'/storage/framework/cache/facade-abc.php');
            self::assertFileExists(
                $base.'/storage/framework/cache/keep-me.php',
                'The facade- prefix must be matched on the basename, not the whole path.'
            );
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_delete_storage_symlink_reports_failure_when_it_is_a_directory(): void
    {
        // File::delete() returns false for a directory, but the return was
        // discarded and success logged regardless.
        File::makeDirectory($this->base.'/public/storage', 0755, true);

        self::assertFalse($this->service->deleteStorageSymlink());
    }

    public function test_delete_storage_symlink_removes_a_dangling_link(): void
    {
        // file_exists() follows the link and answers false for a dangling one —
        // exactly the case most worth cleaning up.
        File::makeDirectory($this->base.'/public', 0755, true);
        symlink($this->base.'/no-such-target', $this->base.'/public/storage');

        self::assertTrue($this->service->deleteStorageSymlink());
        self::assertFalse(is_link($this->base.'/public/storage'));
    }
}
