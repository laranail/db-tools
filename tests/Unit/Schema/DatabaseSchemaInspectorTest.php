<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Schema;

use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Schema\DatabaseSchemaInspector;
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
}
