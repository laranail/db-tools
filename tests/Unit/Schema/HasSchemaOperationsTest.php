<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Schema;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Schema\Concerns\HasSchemaOperations;

final class HasSchemaOperationsTest extends TestCase
{
    private object $ops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ops = new class
        {
            use HasSchemaOperations {
                dropColumnsFromTable as public;
                addColumnIfNotExists as public;
                renameColumnIfExists as public;
                dropTablesIfExist as public;
                dropIndexIfExists as public;
            }
        };

        Schema::create('ops_table', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('legacy')->nullable();
            $t->index('name', 'ops_name_index');
        });
    }

    public function test_add_column_if_not_exists_is_idempotent(): void
    {
        $this->ops->addColumnIfNotExists('ops_table', 'note', function (Blueprint $bp, string $column): void {
            $bp->string($column)->nullable();
        });

        self::assertTrue(Schema::hasColumn('ops_table', 'note'));

        // Second call must not throw even though the column already exists.
        $this->ops->addColumnIfNotExists('ops_table', 'note', function (Blueprint $bp, string $column): void {
            $bp->string($column)->nullable();
        });

        self::assertTrue(Schema::hasColumn('ops_table', 'note'));
    }

    public function test_drop_columns_from_table_skips_missing_columns(): void
    {
        $this->ops->dropColumnsFromTable('ops_table', ['legacy', 'never_existed']);

        self::assertFalse(Schema::hasColumn('ops_table', 'legacy'));
        self::assertTrue(Schema::hasColumn('ops_table', 'name'));
    }

    public function test_rename_column_if_exists(): void
    {
        $this->ops->renameColumnIfExists('ops_table', 'name', 'title');

        self::assertFalse(Schema::hasColumn('ops_table', 'name'));
        self::assertTrue(Schema::hasColumn('ops_table', 'title'));

        // No-op when the source column is gone.
        $this->ops->renameColumnIfExists('ops_table', 'name', 'whatever');
        self::assertFalse(Schema::hasColumn('ops_table', 'whatever'));
    }

    public function test_drop_index_if_exists_drops_present_index(): void
    {
        self::assertTrue(Schema::hasIndex('ops_table', 'ops_name_index'));

        $this->ops->dropIndexIfExists('ops_table', 'ops_name_index');

        self::assertFalse(Schema::hasIndex('ops_table', 'ops_name_index'));
    }

    public function test_drop_index_if_exists_is_safe_no_op_for_missing_index(): void
    {
        // Regression: dropping a non-existent index (or one on a missing table)
        // must be a silent no-op rather than a driver error.
        $this->ops->dropIndexIfExists('ops_table', 'no_such_index');
        $this->ops->dropIndexIfExists('no_such_table', 'no_such_index');

        self::assertTrue(Schema::hasTable('ops_table'));
    }

    public function test_drop_tables_if_exist(): void
    {
        $this->ops->dropTablesIfExist(['ops_table', 'never_created']);

        self::assertFalse(Schema::hasTable('ops_table'));
    }
}
