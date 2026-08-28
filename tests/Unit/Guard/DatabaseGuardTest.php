<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Guard;

use PDO;
use Override;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\DbTools\DbTools;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Guard\DatabaseGuard;
use Illuminate\Database\Events\ConnectionEstablished;
use Simtabi\Laranail\DbTools\Events\DatabaseUnavailable;
use Simtabi\Laranail\DbTools\Guard\Contracts\DatabaseAvailabilityInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseSchemaInspectorInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseConnectionTesterInterface;

final class DatabaseGuardTest extends TestCase
{
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

    public function test_suspend_short_circuits_without_probing_and_resume_restores(): void
    {
        Schema::create('sprockets', fn ($t) => $t->id());

        /** @var DatabaseGuard $guard */
        $guard = app(DatabaseAvailabilityInterface::class);

        self::assertTrue($guard->isAvailable());

        $guard->suspend();
        self::assertTrue($guard->isSuspended());
        self::assertFalse($guard->isAvailable(), 'A suspended guard reports unavailable.');
        self::assertFalse($guard->hasTable('sprockets'), 'A suspended guard finds no tables.');

        $guard->resume();
        self::assertFalse($guard->isSuspended());
        self::assertTrue($guard->isAvailable());
        self::assertTrue($guard->hasTable('sprockets'));
    }

    public function test_resume_preserves_a_custom_prober(): void
    {
        /** @var DatabaseGuard $guard */
        $guard = app(DatabaseAvailabilityInterface::class);
        $guard->probeUsing(fn (?string $c): bool => $c === 'testing');

        $guard->suspend();
        self::assertFalse($guard->isAvailable('testing'), 'Suspension overrides even a custom prober.');

        $guard->resume();
        self::assertTrue($guard->isAvailable('testing'), 'The custom prober survives resume().');
    }

    public function test_when_table_runs_callback_only_when_the_table_exists(): void
    {
        Schema::create('cogs', fn ($t) => $t->id());

        self::assertSame('yes', DbTools::whenTable('cogs', fn (): string => 'yes', 'no'));
        self::assertSame('no', DbTools::whenTable('missing', fn (): string => 'yes', 'no'));
    }

    public function test_probe_fails_fast_on_an_unreachable_host(): void
    {
        // The blackhole connection below has a 1s probe timeout; without a
        // bounded probe this would block for the driver default (~30s).
        $start = microtime(true);
        self::assertFalse(DatabaseGuard::reachable('blackhole'));
        self::assertLessThan(10.0, microtime(true) - $start, 'The probe must fail fast, not hang.');
    }

    public function test_probe_does_not_wipe_a_sqlite_memory_database(): void
    {
        Schema::create('trinkets', fn ($t) => $t->id());
        DB::table('trinkets')->insert(['id' => 1]);

        // A probe of the default (:memory:) connection must reuse it, never
        // purge it — purging :memory: would drop the table and the row.
        self::assertTrue(DatabaseGuard::reachable());

        self::assertTrue(Schema::hasTable('trinkets'));
        self::assertSame(1, DB::table('trinkets')->count());
    }

    public function test_probe_opens_no_throwaway_connection(): void
    {
        DatabaseGuard::reachable('down');

        self::assertNull(
            config('database.connections.__db_tools_probe__'),
            'The probe must not leave a throwaway connection in config.',
        );
    }

    public function test_probe_reuses_the_connection_it_opens(): void
    {
        $established = 0;
        Event::listen(
            ConnectionEstablished::class,
            function () use (&$established): void {
                $established++;
            },
        );

        // First contact with the default connection is the probe; the follow-up
        // query must reuse it rather than open a second connection.
        self::assertTrue(DatabaseGuard::reachable());
        Schema::create('doodads', fn ($t) => $t->id());
        DB::table('doodads')->count();

        self::assertSame(1, $established, 'The probe should open exactly one connection and reuse it.');
    }

    public function test_probe_restores_the_connection_config_it_overlays(): void
    {
        $before = config('database.connections.down');

        self::assertFalse(DatabaseGuard::reachable('down'));

        self::assertSame(
            $before,
            config('database.connections.down'),
            'The probe overlays a connect timeout for its own attempt; it must not leave it '
            . 'in config, where any later rebuild would silently re-apply it.',
        );
    }

    public function test_the_default_connection_shares_one_memo_entry_with_its_name(): void
    {
        $guard = app(DatabaseAvailabilityInterface::class);
        $default = (string) config('database.default');

        $probes = 0;
        $guard->probeUsing(function () use (&$probes): bool {
            $probes++;

            return true;
        });

        // null and the explicit default name address the same connection.
        $guard->isAvailable();
        $guard->isAvailable($default);

        self::assertSame(1, $probes, 'The default connection must not be probed twice.');

        // ...so flushing by name must clear the entry the null form created.
        $guard->flush($default);
        $guard->isAvailable();

        self::assertSame(2, $probes, 'flush($default) must invalidate the null-keyed entry too.');
    }

    public function test_unavailability_is_announced_once_per_transition(): void
    {
        Event::fake([DatabaseUnavailable::class]);

        // Memoization off: without transition tracking this would emit one event
        // per call for the whole outage.
        $guard = new DatabaseGuard(
            app(DatabaseConnectionTesterInterface::class),
            app(DatabaseSchemaInspectorInterface::class),
            memoize: false,
        );

        $guard->isAvailable('down');
        $guard->isAvailable('down');
        $guard->isAvailable('down');

        Event::assertDispatchedTimes(DatabaseUnavailable::class, 1);
    }

    public function test_a_flush_re_announces_a_still_down_connection(): void
    {
        Event::fake([DatabaseUnavailable::class]);

        $guard = app(DatabaseAvailabilityInterface::class);

        $guard->isAvailable('down');
        $guard->flush('down');
        $guard->isAvailable('down');

        Event::assertDispatchedTimes(DatabaseUnavailable::class, 2);
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A deliberately unreachable connection: loopback port 1 refuses the TCP
        // connection instantly (no blackhole hang).
        $app['config']->set('database.connections.down', [
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'port'     => 1,
            'database' => 'nope',
            'username' => 'nope',
            'password' => 'nope',
            'options'  => [PDO::ATTR_TIMEOUT => 1],
        ]);

        // A blackholed host: packets are dropped, so the connect would block
        // for the driver default without a bounded probe. probe_timeout caps it.
        $app['config']->set('laranail.db-tools.guard.probe_timeout', 1);
        $app['config']->set('database.connections.blackhole', [
            'driver'   => 'mysql',
            'host'     => '10.255.255.1',
            'port'     => 3306,
            'database' => 'nope',
            'username' => 'nope',
            'password' => 'nope',
        ]);
    }
}
