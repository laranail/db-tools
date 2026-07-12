<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Schema;

use Simtabi\Laranail\DbTools\Schema\DatabaseConnectionTester;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class DatabaseConnectionTesterTest extends TestCase
{
    private DatabaseConnectionTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tester = new DatabaseConnectionTester;
    }

    public function test_succeeds_on_the_testing_connection(): void
    {
        self::assertTrue($this->tester->test());
        self::assertTrue($this->tester->test('testing'));
    }

    public function test_detailed_returns_a_successful_payload(): void
    {
        $detail = $this->tester->testDetailed();

        self::assertTrue($detail['success']);
        self::assertSame('Connection successful', $detail['message']);
        self::assertSame('sqlite', $detail['driver']);
        self::assertNotEmpty($detail['version']);
        self::assertArrayHasKey('database', $detail);
    }

    public function test_get_driver_is_sqlite(): void
    {
        self::assertSame('sqlite', $this->tester->getDriver());
    }

    public function test_get_version_returns_non_empty_scalar_string(): void
    {
        // Regression: SQLite's sqlite_version() comes back as a scalar row
        // value, not an object with a "version" property. normalizeVersion()
        // must handle that without erroring and return a non-empty string.
        $version = $this->tester->getVersion();

        self::assertIsString($version);
        self::assertNotSame('', $version);
        self::assertMatchesRegularExpression('/^\d+\.\d+/', $version);
    }

    public function test_get_database_name_is_available(): void
    {
        // The in-memory SQLite database name is ":memory:".
        self::assertSame(':memory:', $this->tester->getDatabaseName());
    }

    public function test_unknown_connection_degrades_gracefully(): void
    {
        self::assertFalse($this->tester->test('does-not-exist'));
        self::assertSame('unknown', $this->tester->getDriver('does-not-exist'));
        self::assertNull($this->tester->getVersion('does-not-exist'));
        self::assertNull($this->tester->getDatabaseName('does-not-exist'));
    }
}
