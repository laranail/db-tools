<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Integration;

use Illuminate\Database\Schema\Blueprint as IlluminateBlueprint;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Tests\TestCase;

/**
 * The binding changes the column type every `id()` in the application produces,
 * so it must stay off unless asked for — even with a UUID key type configured,
 * which the macros use and the blueprint override must not act on by itself.
 */
final class BlueprintMacrosDisabledTest extends TestCase
{
    public function test_the_blueprint_is_not_rebound_by_default(): void
    {
        self::assertFalse($this->app->bound(IlluminateBlueprint::class));
    }

    public function test_id_stays_an_auto_increment_integer_by_default(): void
    {
        Schema::create('unbound_things', function (IlluminateBlueprint $table): void {
            $table->id();
        });

        $columns = collect(Schema::getColumns('unbound_things'))->keyBy('name');

        self::assertTrue(
            (bool) $columns['id']['auto_increment'],
            'The blueprint override took effect without the config flag.',
        );
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Deliberately NOT setting schema.blueprint_macros.
        $app['config']->set('laranail.db-tools.using_uuids_for_id', true);
    }
}
