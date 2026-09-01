<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Override;
use Simtabi\Laranail\DbTools\Tests\TestCase;

/**
 * The dropColumnIfExists / dropForeignIfExists macros asked the Schema facade
 * whether a column existed, which always answers for the default connection.
 * A migration running on a second connection therefore made its keep-or-drop
 * decision from a different database than the one it was modifying.
 */
final class FieldGroupMacrosPerConnectionTest extends TestCase
{
    private string $secondaryFile = '';

    protected function tearDown(): void
    {
        if ($this->secondaryFile !== '' && is_file($this->secondaryFile)) {
            @unlink($this->secondaryFile);
        }

        parent::tearDown();
    }

    public function test_drop_column_if_exists_reads_the_blueprint_own_connection(): void
    {
        // Same table name on both connections, but the column exists ONLY on
        // the secondary. Reading the default connection answers "no column"
        // and the drop is skipped — the table keeps a column the migration
        // was written to remove.
        Schema::create('macro_twin', function (Blueprint $t): void {
            $t->id();
        });

        Schema::connection('secondary')->create('macro_twin', function (Blueprint $t): void {
            $t->id();
            $t->string('legacy');
        });

        Schema::connection('secondary')->table('macro_twin', function (Blueprint $t): void {
            $t->dropColumnIfExists('legacy');
        });

        self::assertFalse(Schema::connection('secondary')->hasColumn('macro_twin', 'legacy'));
    }

    public function test_drop_column_if_exists_skips_a_column_absent_on_its_own_connection(): void
    {
        // The mirror image: present on the default connection, absent on the
        // secondary. Reading the default answers "yes" and the macro emits a
        // drop for a column that is not there, which is a driver error.
        Schema::create('macro_mirror', function (Blueprint $t): void {
            $t->id();
            $t->string('legacy');
        });

        Schema::connection('secondary')->create('macro_mirror', function (Blueprint $t): void {
            $t->id();
        });

        Schema::connection('secondary')->table('macro_mirror', function (Blueprint $t): void {
            $t->dropColumnIfExists('legacy');
        });

        self::assertTrue(Schema::hasColumn('macro_mirror', 'legacy'), 'The default connection must be untouched.');
    }

    public function test_drop_foreign_if_exists_accepts_a_constraint_name(): void
    {
        // The guard was Schema::hasColumn($table, $index). The parameter is an
        // INDEX name, and a conventional foreign-key name such as
        // posts_user_id_foreign is not a column — so the guard answered false
        // and the macro silently dropped nothing.
        Schema::create('macro_users', fn (Blueprint $t) => $t->id());

        Schema::create('macro_posts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('macro_users');
        });

        Schema::table('macro_posts', function (Blueprint $t): void {
            $t->dropForeignIfExists('macro_posts_user_id_foreign');
        });

        self::assertSame([], Schema::getForeignKeys('macro_posts'));
    }

    public function test_drop_foreign_if_exists_still_accepts_a_column_name(): void
    {
        Schema::create('macro_users', fn (Blueprint $t) => $t->id());

        Schema::create('macro_posts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('macro_users');
        });

        Schema::table('macro_posts', function (Blueprint $t): void {
            $t->dropForeignIfExists('user_id');
        });

        self::assertSame([], Schema::getForeignKeys('macro_posts'));
    }

    public function test_drop_foreign_if_exists_is_a_no_op_when_nothing_matches(): void
    {
        Schema::create('macro_posts', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('user_id');
        });

        // No foreign key at all: the macro must not emit a drop. On a driver
        // that rejects dropping an absent constraint this is the difference
        // between a clean migration and a failed one.
        Schema::table('macro_posts', function (Blueprint $t): void {
            $t->dropForeignIfExists('macro_posts_user_id_foreign');
            $t->dropForeignIfExists('user_id');
        });

        self::assertTrue(Schema::hasColumn('macro_posts', 'user_id'));
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->secondaryFile = tempnam(sys_get_temp_dir(), 'dbt-macros-').'.sqlite';
        touch($this->secondaryFile);

        $app['config']->set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => $this->secondaryFile,
            'prefix' => '',
        ]);
    }
}
