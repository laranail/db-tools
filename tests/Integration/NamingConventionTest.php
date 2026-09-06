<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Integration;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Providers\DbToolsServiceProvider;

/**
 * Every public name is asserted against the LIVE registries on a booted application, never by
 * grepping the provider.
 *
 * Grepping proves how a registration was written; it does not prove what the framework ended up
 * holding. These are flat global maps, so a second package claiming a key does not collide loudly —
 * it silently replaces the first, and the damage surfaces far away as a missing config or the wrong
 * thing published.
 */
final class NamingConventionTest extends TestCase
{
    public function test_it_reads_its_config_under_the_vendor_scoped_key(): void
    {
        $this->assertIsArray(config('laranail.db-tools'));
        $this->assertNull(config('db-tools'), 'a bare config key is still registered');
    }

    public function test_it_namespaces_every_publish_tag(): void
    {
        $tags = ServiceProvider::publishableGroups();
        $mine = array_values(array_filter($tags, fn (string $t): bool => str_contains($t, 'db-tools')));

        $this->assertNotEmpty($mine, 'the provider registered no publish tags at all');

        foreach ($mine as $tag) {
            $this->assertStringStartsWith('laranail::db-tools-', $tag);
        }

        // The bare tags this package shipped through 0.7 are gone, not merely shadowed.
        $this->assertNotContains('db-tools-config', $tags);
        $this->assertNotContains('db-tools-migrations', $tags);
    }

    public function test_it_publishes_config_where_laravel_reads_the_override_back_from(): void
    {
        // The merge key is dotted (laranail.db-tools), so the override must live at the matching
        // nested path. A flat config/db-tools.php would load as config('db-tools') and be invisible
        // to the merged key — silently, with the packaged defaults still answering.
        $this->assertContains('laranail::db-tools-config', ServiceProvider::publishableGroups());

        $paths = ServiceProvider::pathsToPublish(DbToolsServiceProvider::class, 'laranail::db-tools-config');

        $this->assertContains(config_path('laranail/db-tools.php'), array_values($paths));
    }

    public function test_it_registers_its_commands_under_the_vendor_scoped_name(): void
    {
        $names = array_keys($this->app->make(Kernel::class)->all());
        $mine = array_values(array_filter($names, fn (string $n): bool => str_contains($n, 'db-tools')));

        $this->assertNotEmpty($mine, 'no db-tools command is registered');

        foreach ($mine as $name) {
            $this->assertStringStartsWith('laranail::db-tools.', $name);
        }
    }
}
