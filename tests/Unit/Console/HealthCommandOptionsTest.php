<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Console;

use Simtabi\Laranail\DbTools\Tests\TestCase;

final class HealthCommandOptionsTest extends TestCase
{
    public function test_a_valueless_connection_option_is_treated_as_the_default(): void
    {
        // Symfony hands back '' for `--connection=`. That is not "not supplied":
        // it reached the readiness and guard memos as a distinct key, forking the
        // cache for the very connection it was meant to name.
        $this->artisan('laranail::db-tools.health', ['--connection' => ''])
            ->expectsOutputToContain('Connection: ' . config('database.default'))
            ->assertSuccessful();
    }

    public function test_a_valueless_tables_option_falls_back_to_config(): void
    {
        $this->artisan('laranail::db-tools.health', ['--tables' => ''])
            ->assertSuccessful();
    }
}
