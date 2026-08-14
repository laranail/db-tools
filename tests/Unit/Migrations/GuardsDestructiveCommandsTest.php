<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Migrations;

use Illuminate\Console\Events\CommandStarting;
use RuntimeException;
use Simtabi\Laranail\DbTools\Migrations\GuardsDestructiveCommands;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The gap a `BaseMigration::down()` guard cannot close.
 *
 * Traced through the framework rather than assumed:
 *
 *   migrate:rollback → Migrator::rollback() → runDown()  → down() runs, guarded
 *   migrate:reset    → Migrator::reset()    → runDown()  → down() runs, guarded
 *   migrate:fresh    → db:wipe → dropAllTables()         → NOT guarded
 *   db:wipe          → dropAllTables()                   → NOT guarded
 *
 * An earlier plan put `migrate:reset` in the unguarded column. It is not:
 * `resetMigrations()` runs every migration's `down()`, so guarding it here as
 * well would produce two refusals for one command.
 */
final class GuardsDestructiveCommandsTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';
        config()->set('laranail.db-tools.migrations.allow_rollback', false);

        parent::tearDown();
    }

    private function fire(string $command, array $parameters = []): void
    {
        new GuardsDestructiveCommands()->handle(
            new CommandStarting($command, new ArrayInput($parameters), new NullOutput),
        );
    }

    public function test_it_refuses_migrate_fresh_on_an_unlisted_environment(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('drop every table and re-migrate');

        $this->fire('migrate:fresh');
    }

    public function test_it_refuses_db_wipe_on_an_unlisted_environment(): void
    {
        $this->app['env'] = 'staging';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('drop every table');

        $this->fire('db:wipe');
    }

    public function test_it_leaves_the_commands_that_run_down_alone(): void
    {
        // Both reach Migrator::runDown(), so BaseMigration already guards them.
        // Guarding them here too would mean two refusals for one command.
        $this->app['env'] = 'production';

        $this->fire('migrate:rollback');
        $this->fire('migrate:reset');

        $this->addToAssertionCount(2);
    }

    public function test_it_leaves_unrelated_commands_alone(): void
    {
        $this->app['env'] = 'production';

        $this->fire('migrate');
        $this->fire('db:seed');
        $this->fire('route:list');
        $this->fire('');

        $this->addToAssertionCount(4);
    }

    public function test_it_permits_the_environments_where_dropping_is_normal(): void
    {
        // RefreshDatabase runs migrate:fresh, so a suite could not run at all
        // if this refused under testing.
        $this->fire('migrate:fresh');
        $this->fire('db:wipe');

        $this->addToAssertionCount(2);
    }

    public function test_force_still_works_for_a_deliberate_deploy(): void
    {
        // --force is the framework's own "yes, in production" for these
        // commands. Nobody types it by accident, which is the case that matters.
        $this->app['env'] = 'production';

        $this->fire('migrate:fresh', ['--force' => true]);

        $this->addToAssertionCount(1);
    }

    public function test_the_explicit_override_also_works(): void
    {
        $this->app['env'] = 'production';
        config()->set('laranail.db-tools.migrations.allow_rollback', true);

        $this->fire('db:wipe');

        $this->addToAssertionCount(1);
    }

    public function test_the_listener_is_registered_by_the_provider(): void
    {
        self::assertTrue(
            $this->app->make('events')->hasListeners(CommandStarting::class),
            'The guard listens for CommandStarting; without the registration it never fires.',
        );
    }
}
