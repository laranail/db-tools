<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Console;

use Illuminate\Console\Command;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Console\Concerns\ReadsOptions;
use Simtabi\Laranail\DbTools\Console\Concerns\SupportsNamespacedNames;

/**
 * 0.1.1 removed both concerns on the claim that nothing outside the package
 * could be using them. Consuming applications were: their own commands `use`
 * the db-tools trait to register `vendor::pkg.command` names, and fataled on
 * load against 0.1.1. These pin the restored names until they can be removed.
 */
final class DeprecatedConsoleConcernsTest extends TestCase
{
    public function test_the_old_supports_namespaced_names_still_accepts_a_double_colon_name(): void
    {
        $command = new class extends Command
        {
            use SupportsNamespacedNames;

            protected $signature = 'acme::widgets.sync';
        };

        self::assertSame('acme::widgets.sync', $command->getName());
    }

    public function test_the_old_reads_options_still_provides_its_accessors(): void
    {
        $command = new class extends Command
        {
            use ReadsOptions;

            protected $signature = 'acme:widgets {--only=}';

            public function only(): ?string
            {
                return $this->strOption('only');
            }
        };

        self::assertTrue(method_exists($command, 'strOption'));
        self::assertTrue(method_exists($command, 'strArg'));
        self::assertTrue(method_exists($command, 'listOption'));
    }
}
