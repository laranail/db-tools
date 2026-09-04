<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Http;

use Override;
use Illuminate\Support\Facades\Route;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Schema\SchemaStatus;
use Simtabi\Laranail\DbTools\Schema\SchemaReadinessReport;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Simtabi\Laranail\DbTools\Http\Middleware\EnsureSchemaIsReady;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;

final class EnsureSchemaIsReadyTest extends TestCase
{
    public function test_it_is_auto_registered_on_the_global_middleware_stack(): void
    {
        $kernel = $this->app->make(HttpKernelContract::class);

        self::assertTrue(
            $kernel->hasMiddleware(EnsureSchemaIsReady::class),
            'The provider must auto-register the middleware globally when enabled.',
        );
    }

    public function test_it_stamps_advisory_headers_when_the_schema_is_not_ready(): void
    {
        $this->fakeReport($this->report(SchemaStatus::Pending));

        Route::get('/probe', fn (): string => 'ok');

        $response = $this->get('/probe');

        $response->assertOk();
        $response->assertHeader('X-Schema-Status', 'pending');
        self::assertStringContainsString('required tables are missing', (string) $response->headers->get('X-Schema-Message'));
    }

    public function test_it_reports_an_unreachable_database(): void
    {
        $this->fakeReport($this->report(SchemaStatus::Down));

        Route::get('/probe', fn (): string => 'ok');

        $this->get('/probe')->assertHeader('X-Schema-Status', 'down');
    }

    public function test_it_stamps_no_headers_when_ready(): void
    {
        $this->fakeReport($this->report(SchemaStatus::Ready));

        Route::get('/probe', fn (): string => 'ok');

        $response = $this->get('/probe');

        $response->assertOk();
        self::assertFalse($response->headers->has('X-Schema-Status'));
    }

    public function test_a_ready_result_is_cached_and_short_circuits_later_checks(): void
    {
        $this->fakeReport($this->report(SchemaStatus::Ready));
        Route::get('/probe', fn (): string => 'ok');

        // First request confirms ready and caches it.
        $this->get('/probe')->assertHeaderMissing('X-Schema-Status');

        // Even if the DB now looks broken, the cached "ready" wins for the TTL.
        $this->fakeReport($this->report(SchemaStatus::Down));

        $this->get('/probe')->assertHeaderMissing('X-Schema-Status');
    }

    public function test_the_kill_switch_disables_the_middleware(): void
    {
        config()->set('laranail.db-tools.readiness.middleware.enabled', false);
        $this->fakeReport($this->report(SchemaStatus::Pending));

        Route::get('/probe', fn (): string => 'ok');

        $this->get('/probe')->assertHeaderMissing('X-Schema-Status');
    }

    public function test_the_alias_is_registered(): void
    {
        $aliases = $this->app->make('router')->getMiddleware();

        self::assertArrayHasKey('laranail-db-tools.schema-ready', $aliases);
        self::assertSame(EnsureSchemaIsReady::class, $aliases['laranail-db-tools.schema-ready']);
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Use the array cache so the middleware's "recently ready" short-circuit
        // stays in-process and does not touch the filesystem between tests.
        $app['config']->set('laranail.db-tools.readiness.middleware.cache_store', 'array');
    }

    /**
     * Swap the readiness reporter for one that always returns the given report.
     */
    private function fakeReport(SchemaReadinessReport $report): void
    {
        $this->app->instance(SchemaReadinessInterface::class, new readonly class($report) implements SchemaReadinessInterface
        {
            public function __construct(private SchemaReadinessReport $report) {}

            public function report(array $requiredTables = [], ?string $connection = null): SchemaReadinessReport
            {
                return $this->report;
            }

            public function isReady(array $requiredTables = [], ?string $connection = null): bool
            {
                return $this->report->isReady();
            }

            public function whenReady(callable $callback, mixed $default = null, array $requiredTables = [], ?string $connection = null): mixed
            {
                return $this->report->isReady() ? $callback() : $default;
            }

            public function flush(?string $connection = null): void {}
        });
    }

    private function report(SchemaStatus $status): SchemaReadinessReport
    {
        return new SchemaReadinessReport(
            status: $status,
            reachable: $status !== SchemaStatus::Down,
            hasMigrationsTable: $status === SchemaStatus::Pending || $status === SchemaStatus::Ready,
            missingTables: $status === SchemaStatus::Pending ? ['users'] : [],
            connection: 'testing',
        );
    }
}
