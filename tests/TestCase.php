<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Sluggable\SluggableServiceProvider;
use Simtabi\Laranail\DbTools\Providers\DbToolsServiceProvider;

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
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->connectionUnderTest());
    }

    /**
     * In-memory SQLite unless DB_CONNECTION names a server driver, which is how
     * the `drivers` CI job points the portable-SQL tests at PostgreSQL, MySQL and
     * MariaDB. SQLite alone cannot prove a dialect is right: the json/jsonb
     * mistake these tests pin is invisible there.
     *
     * @return array<string, mixed>
     */
    private function connectionUnderTest(): array
    {
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';

        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        }

        return [
            'driver'   => $driver,
            'host'     => getenv('DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
            'database' => getenv('DB_DATABASE') ?: 'db_tools_test',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'prefix'   => '',
            'charset'  => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
        ];
    }
}
