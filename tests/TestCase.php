<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Simtabi\Laranail\DbTools\Providers\DbToolsServiceProvider;
use Spatie\Sluggable\SluggableServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        // Spatie's provider is auto-discovered in a real app; Testbench needs it
        // registered explicitly so the sluggable config (its action registry,
        // required since spatie/laravel-sluggable v4) is available under test.
        return [
            SluggableServiceProvider::class,
            DbToolsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
