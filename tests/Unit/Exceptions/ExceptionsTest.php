<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use Simtabi\Laranail\DbTools\Exceptions\UuidException;
use Simtabi\Laranail\DbTools\Exceptions\DbToolsException;
use Simtabi\Laranail\DbTools\Exceptions\MissingUuidColumnException;

final class ExceptionsTest extends TestCase
{
    public function test_all_exceptions_extend_the_package_base(): void
    {
        self::assertInstanceOf(DbToolsException::class, UuidException::missingValue('id'));
        self::assertInstanceOf(DbToolsException::class, new MissingUuidColumnException('missing'));
    }

    public function test_uuid_exception_factories_carry_context(): void
    {
        $e = UuidException::invalidFormat('not-a-uuid');

        self::assertSame(1002, $e->getCode());
        self::assertSame('not-a-uuid', $e->getContext()['value']);
    }

    public function test_uuid_exception_missing_value_carries_column(): void
    {
        $e = UuidException::missingValue('uuid');

        self::assertSame(1001, $e->getCode());
        self::assertSame('uuid', $e->getContext()['column']);
    }
}
