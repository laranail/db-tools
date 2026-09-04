<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Schema;

use PDO;
use Override;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Guard\DatabaseGuard;
use Simtabi\Laranail\DbTools\Schema\SchemaStatus;
use Simtabi\Laranail\DbTools\Events\SchemaNotReady;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;
use Simtabi\Laranail\DbTools\Guard\Contracts\DatabaseAvailabilityInterface;

final class SchemaReadinessTest extends TestCase
{
    public function test_down_when_connection_is_unreachable(): void
    {
        $report = $this->readiness()->report(['migrations'], 'down');

        self::assertSame(SchemaStatus::Down, $report->status);
        self::assertFalse($report->reachable);
        self::assertFalse($report->isReady());
    }

    public function test_empty_when_migrations_table_is_absent(): void
    {
        Schema::dropIfExists('migrations');

        $report = $this->readiness()->report(['migrations', 'widgets']);

        self::assertSame(SchemaStatus::Empty, $report->status);
        self::assertTrue($report->reachable);
        self::assertFalse($report->hasMigrationsTable);
    }

    public function test_pending_when_a_required_table_is_missing(): void
    {
        Schema::create('migrations', fn ($t) => $t->id());

        $report = $this->readiness()->report(['migrations', 'widgets']);

        self::assertSame(SchemaStatus::Pending, $report->status);
        self::assertSame(['widgets'], $report->missingTables);
    }

    public function test_ready_when_all_required_tables_present(): void
    {
        Schema::create('migrations', fn ($t) => $t->id());
        Schema::create('widgets', fn ($t) => $t->id());

        $report = $this->readiness()->report(['migrations', 'widgets']);

        self::assertSame(SchemaStatus::Ready, $report->status);
        self::assertTrue($report->isReady());
        self::assertSame([], $report->missingTables);
    }

    public function test_fires_schema_not_ready_event_unless_ready(): void
    {
        Event::fake([SchemaNotReady::class]);
        Schema::dropIfExists('migrations');

        $this->readiness()->report(['migrations']);

        Event::assertDispatched(SchemaNotReady::class);
    }

    public function test_does_not_fire_when_ready(): void
    {
        Event::fake([SchemaNotReady::class]);
        Schema::create('migrations', fn ($t) => $t->id());

        $this->readiness()->report(['migrations']);

        Event::assertNotDispatched(SchemaNotReady::class);
    }

    public function test_suspended_guard_yields_a_down_report(): void
    {
        Schema::create('migrations', fn ($t) => $t->id());

        /** @var DatabaseGuard $guard */
        $guard = app(DatabaseAvailabilityInterface::class);
        $guard->suspend();

        $report = $this->readiness()->report(['migrations']);

        self::assertSame(SchemaStatus::Down, $report->status);
    }

    public function test_flush_re_evaluates_after_the_schema_changes(): void
    {
        $readiness = $this->readiness();

        // Nothing migrated yet.
        self::assertSame(SchemaStatus::Empty, $readiness->report(['migrations'])->status);

        Schema::create('migrations', fn ($t) => $t->id());

        // Memoized: a long-lived process would be stuck on the stale report.
        self::assertSame(SchemaStatus::Empty, $readiness->report(['migrations'])->status);

        $readiness->flush();

        self::assertSame(SchemaStatus::Ready, $readiness->report(['migrations'])->status);
    }

    public function test_flush_by_name_clears_the_default_connections_reports(): void
    {
        $readiness = $this->readiness();

        self::assertSame(SchemaStatus::Empty, $readiness->report(['migrations'])->status);

        Schema::create('migrations', fn ($t) => $t->id());

        // Reported under the null form, flushed by the explicit default name:
        // both must resolve to the same key.
        $readiness->flush((string) config('database.default'));

        self::assertSame(SchemaStatus::Ready, $readiness->report(['migrations'])->status);
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.down', [
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'port'     => 1,
            'database' => 'nope',
            'username' => 'nope',
            'password' => 'nope',
            'options'  => [PDO::ATTR_TIMEOUT => 1],
        ]);
    }

    private function readiness(): SchemaReadinessInterface
    {
        return app(SchemaReadinessInterface::class);
    }
}
