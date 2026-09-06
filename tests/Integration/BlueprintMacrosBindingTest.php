<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Integration;

use RuntimeException;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Schema\BlueprintMacros;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;
use Illuminate\Database\Schema\Blueprint as IlluminateBlueprint;

/**
 * `BlueprintMacros` shipped inert: nothing installed it, so its id()/foreignId()
 * overrides never ran and `registerDriverSetup()` was unreachable from anywhere
 * but a docs example. These tests exist because "the class is correct" and "the
 * class is reached" are different claims, and only the second one matters.
 */
final class BlueprintMacrosBindingTest extends TestCase
{
    protected function tearDown(): void
    {
        BlueprintMacros::flushDriverSetupState();

        parent::tearDown();
    }

    public function test_the_binding_is_installed_when_enabled(): void
    {
        self::assertTrue($this->app->bound(IlluminateBlueprint::class));

        // Go through the schema builder so the connection has its grammar;
        // Connection::getSchemaBuilder() is what installs it.
        $context = ConnectionContext::for(null);
        $context->schema();

        $blueprint = $this->app->make(IlluminateBlueprint::class, [
            'connection' => $context->connection(),
            'table'      => 'probe',
        ]);

        self::assertInstanceOf(BlueprintMacros::class, $blueprint);
    }

    public function test_a_real_migration_gets_a_uuid_primary_key(): void
    {
        // The whole point: Schema::create() must route through the binding.
        Schema::create('bound_things', function (IlluminateBlueprint $table): void {
            $table->id();
            $table->string('name');
        });

        $columns = collect(Schema::getColumns('bound_things'))->keyBy('name');

        self::assertArrayHasKey('id', $columns->all());
        self::assertFalse(
            (bool) $columns['id']['auto_increment'],
            'id() still produced an auto-increment integer, so the binding never took effect.',
        );
        self::assertSame('varchar', strtolower((string) $columns['id']['type_name']));
    }

    public function test_driver_setup_runs_once_per_connection(): void
    {
        $calls = 0;

        BlueprintMacros::registerDriverSetup('sqlite', function () use (&$calls): void {
            $calls++;
        });

        Schema::create('setup_a', fn (IlluminateBlueprint $t) => $t->string('x')->nullable());
        Schema::create('setup_b', fn (IlluminateBlueprint $t) => $t->string('x')->nullable());
        Schema::create('setup_c', fn (IlluminateBlueprint $t) => $t->string('x')->nullable());

        self::assertSame(1, $calls, 'Driver setup ran per blueprint instead of per connection.');
    }

    public function test_flushing_lets_driver_setup_run_again(): void
    {
        $calls = 0;

        BlueprintMacros::registerDriverSetup('sqlite', function () use (&$calls): void {
            $calls++;
        });

        Schema::create('flush_a', fn (IlluminateBlueprint $t) => $t->string('x')->nullable());
        BlueprintMacros::flushDriverSetupState();
        Schema::create('flush_b', fn (IlluminateBlueprint $t) => $t->string('x')->nullable());

        self::assertSame(2, $calls);
    }

    public function test_a_throwing_driver_setup_does_not_break_the_migration(): void
    {
        BlueprintMacros::registerDriverSetup('sqlite', function (): never {
            throw new RuntimeException('deliberate');
        });

        Schema::create('resilient', fn (IlluminateBlueprint $t) => $t->string('x')->nullable());

        self::assertTrue(Schema::hasTable('resilient'));
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('laranail.db-tools.schema.blueprint_macros', true);
        $app['config']->set('laranail.db-tools.using_uuids_for_id', true);
    }
}
