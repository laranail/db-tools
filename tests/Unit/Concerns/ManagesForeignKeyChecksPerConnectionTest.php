<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Override;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Concerns\ManagesForeignKeyChecks;

final class ManagesForeignKeyChecksPerConnectionTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class
        {
            use ManagesForeignKeyChecks {
                withoutForeignKeyChecks as public;
                getForeignKeyCheckNestingLevel as public;
            }
        };
    }

    public function test_nested_calls_restore_correctly_on_a_named_connection(): void
    {
        self::assertSame(0, $this->subject->getForeignKeyCheckNestingLevel('secondary'));

        $this->subject->withoutForeignKeyChecks(function (): void {
            self::assertSame(1, $this->subject->getForeignKeyCheckNestingLevel('secondary'));

            $this->subject->withoutForeignKeyChecks(function (): void {
                self::assertSame(2, $this->subject->getForeignKeyCheckNestingLevel('secondary'));
            }, 'secondary');

            self::assertSame(1, $this->subject->getForeignKeyCheckNestingLevel('secondary'));
        }, 'secondary');

        self::assertSame(0, $this->subject->getForeignKeyCheckNestingLevel('secondary'));
    }

    public function test_connections_track_their_nesting_independently(): void
    {
        $this->subject->withoutForeignKeyChecks(function (): void {
            // Default connection is one level deep...
            self::assertSame(1, $this->subject->getForeignKeyCheckNestingLevel());
            // ...while the secondary connection has not been touched at all.
            self::assertSame(0, $this->subject->getForeignKeyCheckNestingLevel('secondary'));

            $this->subject->withoutForeignKeyChecks(function (): void {
                // Both connections now hold one level each, independently.
                self::assertSame(1, $this->subject->getForeignKeyCheckNestingLevel());
                self::assertSame(1, $this->subject->getForeignKeyCheckNestingLevel('secondary'));
            }, 'secondary');

            // Secondary restored without disturbing the default's counter.
            self::assertSame(1, $this->subject->getForeignKeyCheckNestingLevel());
            self::assertSame(0, $this->subject->getForeignKeyCheckNestingLevel('secondary'));
        });

        self::assertSame(0, $this->subject->getForeignKeyCheckNestingLevel());
        self::assertSame(0, $this->subject->getForeignKeyCheckNestingLevel('secondary'));
    }

    public function test_returns_the_callback_value_on_a_named_connection(): void
    {
        $value = $this->subject->withoutForeignKeyChecks(fn (): string => 'ok', 'secondary');

        self::assertSame('ok', $value);
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A second, independent SQLite connection so the per-connection
        // nesting counter can be exercised in isolation.
        $app['config']->set('database.connections.secondary', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }
}
