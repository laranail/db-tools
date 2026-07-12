<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Concerns\HasSoftDeletesWithUndo;
use Simtabi\Laranail\DbTools\Tests\TestCase;

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
}
