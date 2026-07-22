<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Guard;

use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\DbTools;
use Simtabi\Laranail\DbTools\Guard\Contracts\DatabaseAvailabilityInterface;
use Simtabi\Laranail\DbTools\Guard\DatabaseGuard;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class DatabaseGuardTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A deliberately unreachable connection: loopback port 1 refuses the TCP
        // connection instantly (no blackhole hang).
        $app['config']->set('database.connections.down', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'nope',
            'username' => 'nope',
            'password' => 'nope',
            'options' => [\PDO::ATTR_TIMEOUT => 1],
        ]);
    }

    public function test_the_guard_contract_is_bound(): void
    {
        self::assertInstanceOf(DatabaseGuard::class, app(DatabaseAvailabilityInterface::class));
    }

    public function test_reports_availability_without_throwing(): void
    {
        self::assertTrue(DbTools::isAvailable());
        self::assertFalse(DbTools::isAvailable('down'));
    }

    public function test_has_table_is_connectivity_guarded_and_never_throws(): void
    {
        Schema::create('widgets', fn ($t) => $t->id());

        self::assertTrue(DbTools::tableExists('widgets'));
        self::assertFalse(DbTools::tableExists('missing'));
        self::assertFalse(DbTools::tableExists('widgets', 'down'));
    }

    public function test_when_available_runs_callback_or_default(): void
    {
        self::assertSame('ran', DbTools::whenAvailable(fn (): string => 'ran', 'fallback'));
        self::assertSame('fallback', DbTools::whenAvailable(fn (): string => 'ran', 'fallback', 'down'));
    }

    public function test_probe_is_runtime_swappable(): void
    {
        /** @var DatabaseGuard $guard */
        $guard = app(DatabaseAvailabilityInterface::class);
        $guard->probeUsing(fn (?string $c): bool => $c === 'testing');

        self::assertTrue($guard->isAvailable('testing'));
        self::assertFalse($guard->isAvailable('anything'));

        $guard->probeUsing(null);
        self::assertFalse($guard->isAvailable('down'));
    }

    public function test_static_entry_points_work_without_a_bound_singleton(): void
    {
        Schema::create('gadgets', fn ($t) => $t->id());

        self::assertTrue(DatabaseGuard::reachable());
        self::assertTrue(DatabaseGuard::tableExists('gadgets'));
        self::assertFalse(DatabaseGuard::tableExists('gadgets', 'down'));
    }
}
