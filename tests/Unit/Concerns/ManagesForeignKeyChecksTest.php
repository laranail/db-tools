<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Simtabi\Laranail\DbTools\Concerns\ManagesForeignKeyChecks;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class ManagesForeignKeyChecksTest extends TestCase
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

    public function test_returns_the_callback_value(): void
    {
        $value = $this->subject->withoutForeignKeyChecks(fn (): string => 'done');

        self::assertSame('done', $value);
    }

    public function test_nesting_level_resets_after_completion(): void
    {
        self::assertSame(0, $this->subject->getForeignKeyCheckNestingLevel());

        $this->subject->withoutForeignKeyChecks(function (): void {
            self::assertSame(1, $this->subject->getForeignKeyCheckNestingLevel());

            $this->subject->withoutForeignKeyChecks(function (): void {
                self::assertSame(2, $this->subject->getForeignKeyCheckNestingLevel());
            });

            self::assertSame(1, $this->subject->getForeignKeyCheckNestingLevel());
        });

        self::assertSame(0, $this->subject->getForeignKeyCheckNestingLevel());
    }
}
