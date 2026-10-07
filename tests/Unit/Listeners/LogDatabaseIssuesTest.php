<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Listeners;

use PDO;
use Override;
use RuntimeException;
use Illuminate\Support\Facades\Log;
use Illuminate\Log\Events\MessageLogged;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Events\SchemaReady;
use Simtabi\Laranail\DbTools\Schema\SchemaStatus;
use Simtabi\Laranail\DbTools\Events\SchemaNotReady;
use Simtabi\Laranail\DbTools\Events\DatabaseAvailable;
use Simtabi\Laranail\DbTools\Support\IssueLogThrottle;
use Simtabi\Laranail\DbTools\Events\DatabaseUnavailable;
use Simtabi\Laranail\DbTools\Listeners\LogDatabaseIssues;
use Simtabi\Laranail\DbTools\Schema\SchemaReadinessReport;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;
use Simtabi\Laranail\DbTools\Guard\Contracts\DatabaseAvailabilityInterface;

/**
 * An outage observed by a health check every 15 seconds used to write two
 * warnings per check, about 11,500 lines a day, burying the log that matters
 * during the outage. These pin "log the change of state, then remind".
 */
final class LogDatabaseIssuesTest extends TestCase
{
    /** @var list<MessageLogged> */
    private array $logged = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        IssueLogThrottle::forgetFallback();
        $this->logged = [];
        $this->app->make('events')->listen(MessageLogged::class, function (MessageLogged $message): void {
            if (str_starts_with($message->message, '[db-tools]')) {
                $this->logged[] = $message;
            }
        });
    }

    public function test_the_reminder_interval_is_configurable_under_the_vendor_key(): void
    {
        self::assertSame(300, config('laranail.db-tools.guard.log_reminder_interval'));
        self::assertTrue(config('laranail.db-tools.guard.log_unreachable_schema'));
    }

    public function test_a_sustained_outage_logs_once_then_reminds_once_per_interval(): void
    {
        // Four minutes of a 15-second health check: one warning, not sixteen.
        for ($check = 0; $check < 16; $check++) {
            event(new DatabaseUnavailable('mysql'));
            $this->travel(15)->seconds();
        }

        self::assertCount(1, $this->logged);
        self::assertSame('warning', $this->logged[0]->level);
        self::assertSame('[db-tools] Database connection unavailable.', $this->logged[0]->message);

        // Past the five-minute interval: exactly one reminder.
        $this->travel(61)->seconds();
        event(new DatabaseUnavailable('mysql'));
        event(new DatabaseUnavailable('mysql'));

        self::assertCount(2, $this->logged);
        self::assertSame('warning', $this->logged[1]->level);
        self::assertSame('[db-tools] Database connection still unavailable.', $this->logged[1]->message);
        self::assertArrayHasKey('since', $this->logged[1]->context);
    }

    public function test_recovery_logs_one_info_line_and_re_arms_the_first_warning(): void
    {
        event(new DatabaseUnavailable('mysql'));
        event(new DatabaseAvailable('mysql'));
        event(new DatabaseAvailable('mysql'));

        self::assertSame(['warning', 'info'], $this->levels());
        self::assertSame('[db-tools] Database connection available again.', $this->logged[1]->message);

        // A new outage inside the old reminder window is a new state change.
        event(new DatabaseUnavailable('mysql'));

        self::assertSame(['warning', 'info', 'warning'], $this->levels());
    }

    public function test_availability_without_a_prior_outage_logs_nothing(): void
    {
        event(new DatabaseAvailable('mysql'));
        event(new SchemaReady($this->report(SchemaStatus::Ready)));

        self::assertSame([], $this->logged);
    }

    public function test_connections_are_tracked_independently(): void
    {
        event(new DatabaseUnavailable('mysql'));
        event(new DatabaseUnavailable('reporting'));
        event(new DatabaseUnavailable('mysql'));

        self::assertCount(2, $this->logged);
    }

    public function test_schema_not_ready_logs_on_change_of_status_and_recovers(): void
    {
        event(new SchemaNotReady($this->report(SchemaStatus::Pending)));
        event(new SchemaNotReady($this->report(SchemaStatus::Pending)));
        event(new SchemaNotReady($this->report(SchemaStatus::Empty)));
        event(new SchemaNotReady($this->report(SchemaStatus::Empty)));
        event(new SchemaReady($this->report(SchemaStatus::Ready)));

        self::assertSame(['warning', 'warning', 'info'], $this->levels());
        self::assertStringStartsWith('[db-tools] Schema not ready: ', $this->logged[0]->message);
        self::assertSame('[db-tools] Schema ready again.', $this->logged[2]->message);
    }

    public function test_an_unreachable_schema_is_still_logged_by_default(): void
    {
        event(new SchemaNotReady($this->report(SchemaStatus::Down)));

        self::assertCount(1, $this->logged);
    }

    public function test_an_unreachable_schema_can_be_left_to_the_unavailable_line(): void
    {
        config()->set('laranail.db-tools.guard.log_unreachable_schema', false);

        event(new SchemaNotReady($this->report(SchemaStatus::Down)));
        event(new SchemaReady($this->report(SchemaStatus::Ready)));
        event(new SchemaNotReady($this->report(SchemaStatus::Pending)));

        self::assertSame(['warning'], $this->levels());
        self::assertStringContainsString('pending', (string) json_encode($this->logged[0]->context));
    }

    public function test_an_interval_of_zero_logs_every_check(): void
    {
        config()->set('laranail.db-tools.guard.log_reminder_interval', 0);

        event(new DatabaseUnavailable('mysql'));
        event(new DatabaseUnavailable('mysql'));
        event(new DatabaseUnavailable('mysql'));

        self::assertCount(3, $this->logged);
    }

    public function test_a_cache_store_on_the_failed_database_falls_back_in_process(): void
    {
        // The worst case: the throttle's own store is the database that is down.
        config()->set('laranail.db-tools.guard.log_cache_store', 'down_db');

        for ($check = 0; $check < 5; $check++) {
            event(new DatabaseUnavailable('down'));
        }

        self::assertCount(1, $this->logged);

        $this->travel(301)->seconds();
        event(new DatabaseUnavailable('down'));
        event(new DatabaseAvailable('down'));

        self::assertSame(['warning', 'warning', 'info'], $this->levels());
    }

    public function test_the_listener_never_throws(): void
    {
        config()->set('laranail.db-tools.guard.log_cache_store', 'down_db');
        Log::shouldReceive('warning')->andThrow(new RuntimeException('log sink is down too'));
        Log::shouldReceive('info')->andThrow(new RuntimeException('log sink is down too'));

        $listener = $this->app->make(LogDatabaseIssues::class);
        $listener->handleDatabaseUnavailable(new DatabaseUnavailable('down'));
        $listener->handleSchemaNotReady(new SchemaNotReady($this->report(SchemaStatus::Down)));
        $listener->handleDatabaseAvailable(new DatabaseAvailable('down'));
        $listener->handleSchemaReady(new SchemaReady($this->report(SchemaStatus::Ready)));

        $this->addToAssertionCount(1);
    }

    public function test_repeated_health_checks_against_a_down_database_write_two_lines(): void
    {
        // The reported shape: every check is a fresh request, so the guard's
        // in-process transition memo is gone and both events fire every time.
        for ($check = 0; $check < 20; $check++) {
            $this->app->make(SchemaReadinessInterface::class)->flush();
            $this->app->make(SchemaReadinessInterface::class)->report(connection: 'down');
            $this->travel(15)->seconds();
        }

        self::assertCount(2, $this->logged, implode("\n", array_map(
            static fn (MessageLogged $m): string => $m->message,
            $this->logged,
        )));
    }

    public function test_the_guard_and_readiness_announce_success_for_recovery(): void
    {
        $this->app->make(DatabaseAvailabilityInterface::class)->isAvailable('down');
        $this->app->make(DatabaseAvailabilityInterface::class)->probeUsing(static fn (): bool => true);
        $this->app->make(DatabaseAvailabilityInterface::class)->isAvailable('down');

        self::assertSame(['warning', 'info'], $this->levels());
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logging.default', 'null');
        $app['config']->set('cache.default', 'array');

        $app['config']->set('database.connections.down', [
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'port'     => 1,
            'database' => 'nope',
            'username' => 'nope',
            'password' => 'nope',
            'options'  => [PDO::ATTR_TIMEOUT => 1],
        ]);
        $app['config']->set('cache.stores.down_db', [
            'driver'     => 'database',
            'connection' => 'down',
            'table'      => 'cache',
        ]);
    }

    /**
     * @return list<string>
     */
    private function levels(): array
    {
        return array_map(static fn (MessageLogged $m): string => $m->level, $this->logged);
    }

    private function report(SchemaStatus $status): SchemaReadinessReport
    {
        return new SchemaReadinessReport(
            status: $status,
            reachable: $status !== SchemaStatus::Down,
            hasMigrationsTable: in_array($status, [SchemaStatus::Pending, SchemaStatus::Ready], true),
            missingTables: $status === SchemaStatus::Ready ? [] : ['migrations'],
            connection: 'mysql',
        );
    }
}
