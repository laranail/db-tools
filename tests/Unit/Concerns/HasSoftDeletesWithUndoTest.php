<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Simtabi\Laranail\DbTools\Concerns\HasSoftDeletesWithUndo;

final class UndoFixture extends Model
{
    use HasSoftDeletesWithUndo;
    use SoftDeletes;

    protected $table = 'undo_fixtures';

    protected $guarded = [];
}

final class UndoActor extends Authenticatable
{
    protected $table = 'undo_actors';

    protected $guarded = [];
}

/**
 * A model whose table carries no restored_at column at all.
 */
final class UndoNoStampFixture extends Model
{
    use HasSoftDeletesWithUndo;
    use SoftDeletes;

    protected $table = 'undo_no_stamp';

    protected $guarded = [];
}

/**
 * Exposes the protected stamp so it can be pinned in isolation from
 * Laravel's own restore(), which flushes the whole model.
 */
final class ExposedStampFixture extends Model
{
    use HasSoftDeletesWithUndo {
        stampRestoredAt as public;
    }
    use SoftDeletes;

    protected $table = 'undo_fixtures';

    protected $guarded = [];
}

final class HasSoftDeletesWithUndoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('undo_fixtures', function ($t): void {
            $t->id();
            $t->string('name');
            $t->softDeletesWithUndo();
            $t->timestamps();
        });

        Schema::create('undo_actors', function ($t): void {
            $t->id();
            $t->timestamps();
        });

        Schema::create('undo_no_stamp', function ($t): void {
            $t->id();
            $t->string('name');
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('soft_delete_history', function ($t): void {
            $t->softDeleteHistory();
        });
    }

    public function test_soft_delete_records_a_deleted_history_row(): void
    {
        $model = UndoFixture::create(['name' => 'first']);
        $model->delete();

        $rows = $model->softDeleteHistory()->get();

        self::assertCount(1, $rows);
        self::assertSame('deleted', $rows->first()->action);
        self::assertNull($rows->first()->actor_id);
        self::assertSame(UndoFixture::class, $rows->first()->record_type);
    }

    public function test_restore_stamps_restored_at_and_records_a_restored_row(): void
    {
        $model = UndoFixture::create(['name' => 'second']);
        $model->delete();
        $model->restore();

        self::assertNotNull($model->fresh()->restored_at);

        $actions = $model->softDeleteHistory()->pluck('action')->all();

        self::assertContains('deleted', $actions);
        self::assertContains('restored', $actions);
        self::assertSame(2, $model->softDeleteHistory()->count());
    }

    public function test_actor_is_the_authenticated_id_when_logged_in(): void
    {
        $actor = UndoActor::create();
        Auth::login($actor);

        $model = UndoFixture::create(['name' => 'third']);
        $model->delete();

        self::assertSame(
            (string) $actor->getKey(),
            (string) $model->softDeleteHistory()->first()->actor_id,
        );
    }

    public function test_force_delete_does_not_record_history(): void
    {
        $model = UndoFixture::create(['name' => 'fourth']);
        $key = $model->getKey();
        $model->forceDelete();

        $count = $model->newQuery()->getConnection()
            ->table('soft_delete_history')
            ->where('record_id', $key)
            ->count();

        self::assertSame(0, $count);
    }

    public function test_the_stamp_writes_only_its_own_column(): void
    {
        $model = ExposedStampFixture::create(['name' => 'original']);
        $original = $model->fresh()->updated_at;

        // The stamp is documented as "writing only that single column
        // quietly", but it set the attribute and called saveQuietly(), which
        // flushes EVERY dirty attribute and bumps updated_at. An unrelated
        // in-memory edit was persisted with no model event firing at all.
        //
        // (Laravel's own restore() calls save() and does flush everything —
        // that is framework behaviour this trait does not control. What the
        // trait owns is the stamp, so that is what is pinned here.)
        $model->name = 'edited in memory';
        $model->stampRestoredAt();

        $stored = ExposedStampFixture::query()->findOrFail($model->getKey());

        self::assertNotNull($stored->restored_at, 'The stamp must write its own column.');
        self::assertSame('original', $stored->name, 'The stamp must not persist unrelated edits.');
        self::assertEquals($original, $stored->updated_at, 'The stamp must not touch timestamps.');
    }

    public function test_restore_succeeds_when_the_table_has_no_restored_at_column(): void
    {
        $model = UndoNoStampFixture::create(['name' => 'no stamp']);
        $model->delete();

        // The stamp ran unguarded in the "restored" listener, after the
        // restore had already committed — so a table without the column
        // restored the row and then threw.
        $model->restore();

        self::assertNull($model->fresh()->deleted_at);
    }
}
