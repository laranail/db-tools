<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Migrations;

use RuntimeException;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Simtabi\Laranail\DbTools\Migrations\ReversalPolicy;

/**
 * `down()` drops tables, and `migrate:rollback` asks for no confirmation. On a
 * live installation that is one mistyped command — or a deploy step that runs
 * it on failure — between the customer and an empty database.
 */
final class ReversalPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        config()->set('laranail.db-tools.migrations.allow_rollback', false);
        config()->set('laranail.db-tools.migrations.reversible_environments', ReversalPolicy::DEFAULT_ENVIRONMENTS);

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reversibleEnvironments(): iterable
    {
        yield 'local' => ['local'];
        yield 'development' => ['development'];
        yield 'dev' => ['dev'];
        yield 'testing' => ['testing'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unlistedEnvironments(): iterable
    {
        yield 'staging' => ['staging'];
        yield 'uat' => ['uat'];
        yield 'demo' => ['demo'];
        yield 'prod' => ['prod'];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function truthyOverrides(): iterable
    {
        yield 'bool' => [true];
        yield 'string true' => ['true'];
        yield 'string one' => ['1'];
        yield 'int one' => [1];
        yield 'yes' => ['yes'];
        yield 'on' => ['on'];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function falsyOverrides(): iterable
    {
        yield 'bool' => [false];
        yield 'string false' => ['false'];
        yield 'string zero' => ['0'];
        yield 'int zero' => [0];
        yield 'empty' => [''];
        yield 'no' => ['no'];
        yield 'a word that is not a bool' => ['maybe'];
        yield 'null' => [null];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedEnvironmentLists(): iterable
    {
        yield 'empty array' => [[]];
        yield 'a string' => ['not-an-array'];
        yield 'null' => [null];
        yield 'an int' => [42];
    }

    #[DataProvider('reversibleEnvironments')]
    public function test_it_permits_environments_where_dropping_the_schema_is_normal(string $environment): void
    {
        $this->pretendEnvironment($environment);

        self::assertTrue(ReversalPolicy::isPermitted());
    }

    public function test_testing_is_permitted_because_refresh_database_runs_migrate_fresh(): void
    {
        // A suite that could not rebuild its schema could not run at all.
        self::assertContains('testing', ReversalPolicy::DEFAULT_ENVIRONMENTS);
    }

    public function test_it_refuses_production(): void
    {
        $this->pretendEnvironment('production');

        self::assertFalse(ReversalPolicy::isPermitted());

        $this->expectException(RuntimeException::class);
        ReversalPolicy::guard();
    }

    #[DataProvider('unlistedEnvironments')]
    public function test_it_fails_closed_for_an_environment_nobody_listed(string $environment): void
    {
        // Not permitted because it is unlisted, rather than permitted because it
        // is not literally 'production' — staging holds real data too.
        $this->pretendEnvironment($environment);

        self::assertFalse(ReversalPolicy::isPermitted());
    }

    public function test_the_refusal_names_the_environment_the_alternatives_and_the_override(): void
    {
        $this->pretendEnvironment('staging');

        try {
            ReversalPolicy::guard();
            self::fail('Expected a refusal.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('staging', $e->getMessage());
            self::assertStringContainsString('local', $e->getMessage());
            self::assertStringContainsString('DB_TOOLS_ALLOW_ROLLBACK', $e->getMessage());
            self::assertStringContainsString('backup', $e->getMessage());
        }
    }

    public function test_it_names_the_operation_it_refused(): void
    {
        $this->pretendEnvironment('production');

        $this->expectExceptionMessage('drop every table');
        ReversalPolicy::guard('drop every table');
    }

    public function test_an_operator_can_opt_in_explicitly(): void
    {
        $this->pretendEnvironment('production');
        config()->set('laranail.db-tools.migrations.allow_rollback', true);

        self::assertTrue(ReversalPolicy::isPermitted());

        ReversalPolicy::guard();
        $this->addToAssertionCount(1);
    }

    public function test_the_override_is_read_through_config_not_env(): void
    {
        // config:cache is routine on exactly the servers where this guard
        // matters, and env() returns null once the configuration is cached —
        // which would shut the escape hatch for the one operator who needs it.
        $this->pretendEnvironment('production');
        putenv('DB_TOOLS_ALLOW_ROLLBACK=true');
        config()->set('laranail.db-tools.migrations.allow_rollback', false);

        try {
            self::assertFalse(ReversalPolicy::isPermitted());
        } finally {
            putenv('DB_TOOLS_ALLOW_ROLLBACK');
        }
    }

    #[DataProvider('truthyOverrides')]
    public function test_it_accepts_the_override_in_the_shapes_an_env_file_produces(mixed $value): void
    {
        $this->pretendEnvironment('production');
        config()->set('laranail.db-tools.migrations.allow_rollback', $value);

        self::assertTrue(ReversalPolicy::isPermitted());
    }

    #[DataProvider('falsyOverrides')]
    public function test_anything_else_leaves_the_guard_on(mixed $value): void
    {
        $this->pretendEnvironment('production');
        config()->set('laranail.db-tools.migrations.allow_rollback', $value);

        self::assertFalse(ReversalPolicy::isPermitted());
    }

    public function test_the_environment_list_is_configurable(): void
    {
        $this->pretendEnvironment('sandbox');
        config()->set('laranail.db-tools.migrations.reversible_environments', ['sandbox']);

        self::assertTrue(ReversalPolicy::isPermitted());
        self::assertSame(['sandbox'], ReversalPolicy::environments());
    }

    #[DataProvider('malformedEnvironmentLists')]
    public function test_it_falls_back_to_the_defaults_for_a_list_that_is_not_one(mixed $value): void
    {
        config()->set('laranail.db-tools.migrations.reversible_environments', $value);

        self::assertSame(ReversalPolicy::DEFAULT_ENVIRONMENTS, ReversalPolicy::environments());
    }

    public function test_environments_compare_case_insensitively(): void
    {
        $this->pretendEnvironment('LOCAL');

        self::assertTrue(ReversalPolicy::isPermitted());
    }

    private function pretendEnvironment(string $environment): void
    {
        $this->app['env'] = $environment;
    }
}
