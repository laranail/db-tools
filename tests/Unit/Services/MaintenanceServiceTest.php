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
}
