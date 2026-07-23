<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Schema;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Override;
use PDO;
use Simtabi\Laranail\DbTools\Events\SchemaNotReady;
use Simtabi\Laranail\DbTools\Guard\Contracts\DatabaseAvailabilityInterface;
use Simtabi\Laranail\DbTools\Guard\DatabaseGuard;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;
use Simtabi\Laranail\DbTools\Schema\SchemaStatus;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class SchemaReadinessTest extends TestCase
{
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.down', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'nope',
            'username' => 'nope',
            'password' => 'nope',
            'options' => [PDO::ATTR_TIMEOUT => 1],
        ]);
    }

    private function readiness(): SchemaReadinessInterface
    {
        return app(SchemaReadinessInterface::class);
    }

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
}
