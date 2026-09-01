<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Support;

use Illuminate\Support\Facades\Config;
use Simtabi\Laranail\DbTools\Support\BootWithoutDatabase;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class BootWithoutDatabaseTest extends TestCase
{
    public function test_swaps_only_database_backed_drivers(): void
    {
        Config::set('session.driver', 'database');
        Config::set('cache.default', 'database');

        $changed = BootWithoutDatabase::degradeToFilesystem();

        self::assertSame('file', Config::get('session.driver'));
        self::assertSame('file', Config::get('cache.default'));
        self::assertSame(['session.driver' => 'file', 'cache.default' => 'file'], $changed);
    }

    public function test_leaves_non_database_drivers_untouched(): void
    {
        Config::set('session.driver', 'redis');
        Config::set('cache.default', 'redis');

        $changed = BootWithoutDatabase::degradeToFilesystem();

        self::assertSame('redis', Config::get('session.driver'));
        self::assertSame('redis', Config::get('cache.default'));
        self::assertSame([], $changed);
    }

    public function test_honours_a_custom_map(): void
    {
        Config::set('queue.default', 'database');

        $changed = BootWithoutDatabase::degradeToFilesystem([
            'queue.default' => ['database' => 'sync'],
        ]);

        self::assertSame('sync', Config::get('queue.default'));
        self::assertSame(['queue.default' => 'sync'], $changed);
    }
}
