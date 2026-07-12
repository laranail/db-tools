<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Schema\Concerns\HasSchemaInspection;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class SchemaInspectionFixture extends Model
{
    use HasSchemaInspection;

    protected $table = 'schema_inspection_fixtures';

    protected $guarded = [];
}

final class HasSchemaInspectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SchemaInspectionFixture::clearSchemaCache();

        Schema::create('schema_inspection_fixtures', function ($t): void {
            $t->id();
            $t->string('name');
        });
    }

    protected function tearDown(): void
    {
        SchemaInspectionFixture::clearSchemaCache();

        parent::tearDown();
    }

    public function test_resolves_table_name_and_columns(): void
    {
        self::assertSame('schema_inspection_fixtures', SchemaInspectionFixture::getSchemaTableName());
        self::assertContains('name', SchemaInspectionFixture::getSchemaColumnNames());
        self::assertTrue(SchemaInspectionFixture::schemaHasColumn('name'));
        self::assertFalse(SchemaInspectionFixture::schemaHasColumn('missing'));
    }

    public function test_columns_are_cached_and_survive_a_schema_change_until_cleared(): void
    {
        // Prime the static cache.
        self::assertFalse(SchemaInspectionFixture::schemaHasColumn('extra'));

        Schema::table('schema_inspection_fixtures', function ($t): void {
            $t->string('extra')->nullable();
        });

        // Stale cache still reports the pre-change column set.
        self::assertFalse(SchemaInspectionFixture::schemaHasColumn('extra'));

        // Invalidation refreshes from the live schema.
        SchemaInspectionFixture::clearSchemaCache();

        self::assertTrue(SchemaInspectionFixture::schemaHasColumn('extra'));
    }
}
