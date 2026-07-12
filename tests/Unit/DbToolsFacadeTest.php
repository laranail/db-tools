<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit;

use Simtabi\Laranail\DbTools\DbTools;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class DbToolsFacadeTest extends TestCase
{
    public function test_without_foreign_key_checks_returns_callback_result(): void
    {
        $result = DbTools::withoutForeignKeyChecks(fn (): string => 'ok');

        self::assertSame('ok', $result);
    }
}
