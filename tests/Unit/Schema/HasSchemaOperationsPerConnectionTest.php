<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Override;
use Simtabi\Laranail\DbTools\Schema\Concerns\HasSchemaOperations;
use Simtabi\Laranail\DbTools\Tests\TestCase;

/**
 * Every operation in HasSchemaOperations went through the Schema facade, which
 * always answers for the default connection. A migration running against a
 * second database therefore read the wrong schema and wrote to the wrong one.
 *
 * Each test creates a table of the SAME name on BOTH connections, so a method
 * that ignores its $connection argument still finds a table to act on and
 * silently mutates the wrong database rather than erroring.
 */
final class HasSchemaOperationsPerConnectionTest extends TestCase
{
    private object $ops;

    private string $secondaryFile = '';

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

        foreach ([null, 'secondary'] as $connection) {
            Schema::connection($connection)->create('twin', function (Blueprint $t): void {
                $t->id();
                $t->string('name');
                $t->string('legacy')->nullable();
                $t->index('name', 'twin_name_index');
            });
        }
    }

    protected function tearDown(): void
    {
        if ($this->secondaryFile !== '' && is_file($this->secondaryFile)) {
            @unlink($this->secondaryFile);
        }

        parent::tearDown();
    }

    public function test_add_column_targets_the_named_connection_only(): void
    {
        $this->ops->addColumnIfNotExists('twin', 'note', function (Blueprint $bp, string $column): void {
            $bp->string($column)->nullable();
        }, 'secondary');

        self::assertTrue(Schema::connection('secondary')->hasColumn('twin', 'note'));
        self::assertFalse(Schema::hasColumn('twin', 'note'), 'The default connection must be untouched.');
    }

    public function test_drop_columns_targets_the_named_connection_only(): void
    {
        $this->ops->dropColumnsFromTable('twin', 'legacy', 'secondary');

        self::assertFalse(Schema::connection('secondary')->hasColumn('twin', 'legacy'));
        self::assertTrue(Schema::hasColumn('twin', 'legacy'), 'The default connection must be untouched.');
    }

    public function test_rename_column_targets_the_named_connection_only(): void
    {
        $this->ops->renameColumnIfExists('twin', 'name', 'title', 'secondary');

        self::assertTrue(Schema::connection('secondary')->hasColumn('twin', 'title'));
        self::assertFalse(Schema::hasColumn('twin', 'title'), 'The default connection must be untouched.');
    }

    public function test_drop_index_targets_the_named_connection_only(): void
    {
        $this->ops->dropIndexIfExists('twin', 'twin_name_index', 'secondary');

        self::assertFalse(Schema::connection('secondary')->hasIndex('twin', 'twin_name_index'));
        self::assertTrue(Schema::hasIndex('twin', 'twin_name_index'), 'The default connection must be untouched.');
    }

    public function test_drop_tables_targets_the_named_connection_only(): void
    {
        $this->ops->dropTablesIfExist('twin', 'secondary');

        self::assertFalse(Schema::connection('secondary')->hasTable('twin'));
        self::assertTrue(Schema::hasTable('twin'), 'The default connection must be untouched.');
    }

    public function test_operations_still_default_to_the_default_connection(): void
    {
        $this->ops->dropColumnsFromTable('twin', 'legacy');

        self::assertFalse(Schema::hasColumn('twin', 'legacy'));
        self::assertTrue(Schema::connection('secondary')->hasColumn('twin', 'legacy'));
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A file, not :memory:. Two in-memory SQLite connections would each get
        // their own blank database, which is enough here, but a file makes the
        // "wrote to the wrong database" failure legible when it happens.
        $this->secondaryFile = tempnam(sys_get_temp_dir(), 'dbt-ops-').'.sqlite';
        touch($this->secondaryFile);

        $app['config']->set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => $this->secondaryFile,
            'prefix' => '',
        ]);
    }
}
