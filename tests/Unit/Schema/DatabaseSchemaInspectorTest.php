<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Schema;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Simtabi\Laranail\DbTools\Schema\DatabaseSchemaInspector;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class DatabaseSchemaInspectorTest extends TestCase
{
    private DatabaseSchemaInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inspector = new DatabaseSchemaInspector;

        Schema::create('inspector_widgets', function ($t): void {
            $t->id();
            $t->string('name');
            $t->string('sku')->nullable();
            $t->timestamps();
        });
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function postgresSchemaCases(): array
    {
        return [
            'search_path wins' => [['search_path' => 'tenant_a'], 'tenant_a'],
            'comma list takes first' => [['search_path' => 'tenant_a, public'], 'tenant_a'],
            'array takes first' => [['search_path' => ['tenant_a', 'public']], 'tenant_a'],
            'falls back to schema' => [['schema' => 'reporting'], 'reporting'],
            'defaults to public' => [[], 'public'],
            'blank defaults' => [['search_path' => '   '], 'public'],
        ];
    }

    public function test_get_tables_lists_created_tables(): void
    {
        $tables = $this->inspector->getTables();

        // SQLite's getTableListing() returns schema-qualified names
        // (e.g. "main.inspector_widgets"), so match on the suffix.
        $names = array_map(
            static fn (string $t): string => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t,
            $tables,
        );

        self::assertContains('inspector_widgets', $names);
    }

    public function test_has_table(): void
    {
        self::assertTrue($this->inspector->hasTable('inspector_widgets'));
        self::assertFalse($this->inspector->hasTable('inspector_missing'));
    }

    public function test_get_columns(): void
    {
        $columns = $this->inspector->getColumns('inspector_widgets');

        self::assertContains('id', $columns);
        self::assertContains('name', $columns);
        self::assertContains('sku', $columns);
    }

    public function test_get_columns_for_missing_table_returns_empty(): void
    {
        self::assertSame([], $this->inspector->getColumns('inspector_missing'));
    }

    public function test_has_column_and_has_columns(): void
    {
        self::assertTrue($this->inspector->hasColumn('inspector_widgets', 'name'));
        self::assertFalse($this->inspector->hasColumn('inspector_widgets', 'nope'));

        self::assertTrue($this->inspector->hasColumns('inspector_widgets', ['id', 'name', 'sku']));
        self::assertFalse($this->inspector->hasColumns('inspector_widgets', ['id', 'nope']));
    }

    public function test_get_table_count_reflects_created_tables(): void
    {
        $before = $this->inspector->getTableCount();

        Schema::create('inspector_gadgets', function ($t): void {
            $t->id();
        });

        self::assertSame($before + 1, $this->inspector->getTableCount());
    }

    public function test_get_table_count_handles_empty_result_path(): void
    {
        // Regression: the COUNT(*) query must never error and must coerce a
        // null/empty result to 0. On a fresh in-memory SQLite connection the
        // count is a real integer (>= 0), never an error.
        $count = $this->inspector->getTableCount();

        self::assertIsInt($count);
        self::assertGreaterThanOrEqual(0, $count);
    }

    public function test_get_table_count_for_unknown_connection_returns_zero(): void
    {
        self::assertSame(0, $this->inspector->getTableCount('does-not-exist'));
    }

    /**
     * @param  array<string, mixed>  $connectionConfig
     */
    #[DataProvider('postgresSchemaCases')]
    public function test_postgres_schema_is_read_from_the_connection_being_counted(
        array $connectionConfig,
        string $expected,
    ): void {
        // getTableCount() hardcoded config('database.connections.pgsql.schema'),
        // so counting connection "analytics" read a different connection's
        // schema — and with nothing literally named "pgsql", silently counted
        // "public".
        config()->set('database.connections.analytics', ['driver' => 'pgsql'] + $connectionConfig);

        $method = new ReflectionMethod(DatabaseSchemaInspector::class, 'postgresSchema');

        self::assertSame(
            $expected,
            $method->invoke(new DatabaseSchemaInspector, ConnectionContext::for('analytics')),
        );
    }
}
