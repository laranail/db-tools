<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Seeding\Concerns;

use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use ReflectionProperty;

/**
 * Progress output from a seeder that may or may not have a console.
 *
 * ## The problem this exists for
 *
 * `Seeder::$command` is a **typed, non-nullable property that Laravel assigns
 * only for console runs**. Under `$this->seed()` in a test it is never
 * initialised — and an uninitialised typed property is not null, so `?->` does
 * not guard it and `isset()` reads false while PHPStan insists the property
 * cannot be null. Reading it throws `Error: Typed property … must not be
 * accessed before initialization`.
 *
 * So every seeder that wants to report progress needs this check, and every
 * seeder that forgets it works in `artisan db:seed` and dies in the test suite.
 * `ReflectionProperty::isInitialized()` asks the question actually being asked.
 */
trait InteractsWithSeederOutput
{
    /**
     * Methods a seeder may call on the console.
     *
     * An allow-list because {@see tell()} takes the method name as a string,
     * and dispatching a caller-supplied string onto the command object means
     * any method on it is reachable — including `call()`, which runs another
     * artisan command. The original had no list.
     *
     * @var list<string>
     */
    private const array TELLABLE = ['info', 'line', 'comment', 'warn', 'error', 'newLine'];

    /**
     * Write a line, if there is anywhere to write it.
     *
     * A no-op without a console, so a seeder can report progress freely without
     * every call site guarding.
     */
    protected function tell(string $method, string $message = ''): void
    {
        $command = $this->console();

        if (! $command instanceof Command || ! in_array($method, self::TELLABLE, true)) {
            return;
        }

        if ($method === 'newLine') {
            $command->newLine();

            return;
        }

        $command->{$method}($message);
    }

    protected function info(string $message): void
    {
        $this->tell('info', $message);
    }

    protected function warn(string $message): void
    {
        $this->tell('warn', $message);
    }

    protected function comment(string $message): void
    {
        $this->tell('comment', $message);
    }

    /**
     * The console command driving this seeder, or null when there is none.
     *
     * The returned instance is what callers must use. The version this replaces
     * assigned this to a local, checked the local for null, and then read
     * `$this->command` anyway — which works, but leaves the analyser unable to
     * narrow anything and the guard doing nothing for the property access it
     * was written to protect.
     */
    protected function console(): ?Command
    {
        if (! $this instanceof Seeder) {
            return null;
        }

        $property = new ReflectionProperty(Seeder::class, 'command');

        if (! $property->isInitialized($this)) {
            return null;
        }

        $command = $property->getValue($this);

        return $command instanceof Command ? $command : null;
    }
}
