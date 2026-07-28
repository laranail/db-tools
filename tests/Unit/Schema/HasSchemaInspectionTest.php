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

class SchemaInspectionParent extends Model
{
    use HasSchemaInspection;

    protected $table = 'schema_inspection_parents';

    protected $guarded = [];
}

/**
 * A subclass that does NOT use the trait itself. Trait statics are shared down
 * the inheritance chain, so this class reads the parent's cache.
 */
final class SchemaInspectionChild extends SchemaInspectionParent
{
    protected $table = 'schema_inspection_children';
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

        Schema::create('schema_inspection_parents', function ($t): void {
            $t->id();
            $t->string('parent_only');
        });

        Schema::create('schema_inspection_children', function ($t): void {
            $t->id();
            $t->string('child_only');
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

    public function test_a_subclass_reads_its_own_table_not_its_parents(): void
    {
        // A static declared in a trait is shared down the inheritance chain,
        // and every write here used self::, so whichever class was asked
        // FIRST populated the cache for the whole hierarchy. A subclass on its
        // own table then reported its parent's columns — a silent wrong
        // answer, not an error.
        self::assertSame('schema_inspection_parents', SchemaInspectionParent::getSchemaTableName());
        self::assertTrue(SchemaInspectionParent::schemaHasColumn('parent_only'));

        self::assertSame('schema_inspection_children', SchemaInspectionChild::getSchemaTableName());
        self::assertTrue(SchemaInspectionChild::schemaHasColumn('child_only'));
        self::assertFalse(SchemaInspectionChild::schemaHasColumn('parent_only'));
    }

    public function test_the_subclass_is_correct_when_asked_first(): void
    {
        // The mirror image: order must not decide the answer.
        self::assertTrue(SchemaInspectionChild::schemaHasColumn('child_only'));
        self::assertTrue(SchemaInspectionParent::schemaHasColumn('parent_only'));
        self::assertFalse(SchemaInspectionParent::schemaHasColumn('child_only'));
    }

    public function test_columns_are_read_from_the_models_own_connection(): void
    {
        config()->set('database.connections.other', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Same class, same table name, different connection — and on that
        // connection the table has a different shape.
        Schema::connection('other')->create('schema_inspection_fixtures', function ($t): void {
            $t->id();
            $t->string('elsewhere');
        });

        self::assertTrue(SchemaInspectionFixture::schemaHasColumn('name'));

        $onOther = (new SchemaInspectionFixture)->setConnection('other');

        self::assertTrue($onOther->schemaColumns() !== []);
        self::assertContains('elsewhere', $onOther->schemaColumns());
        self::assertNotContains('name', $onOther->schemaColumns());
    }

    public function test_clear_all_schema_caches_reaches_every_class(): void
    {
        SchemaInspectionParent::schemaHasColumn('parent_only');
        SchemaInspectionFixture::schemaHasColumn('name');

        Schema::table('schema_inspection_parents', fn ($t) => $t->string('added_later')->nullable());
        Schema::table('schema_inspection_fixtures', fn ($t) => $t->string('added_later')->nullable());

        SchemaInspectionFixture::clearAllSchemaCaches();

        self::assertTrue(SchemaInspectionParent::schemaHasColumn('added_later'));
        self::assertTrue(SchemaInspectionFixture::schemaHasColumn('added_later'));
    }
}
