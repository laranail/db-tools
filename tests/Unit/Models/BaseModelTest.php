<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Models;

use Override;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Models\BaseModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class WidgetModel extends BaseModel
{
    protected $table = 'widgets';

    protected $guarded = [];

    protected $enforceUuid = false;
}

final class ScopedWidgetModel extends BaseModel
{
    protected $table = 'widgets';

    protected $guarded = [];

    protected $enforceUuid = false;

    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope('visible', fn ($query) => $query->where('name', 'visible'));
    }
}

final class BaseModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function ($t): void {
            $t->id();
            $t->string('uuid')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
        });
    }

    public function test_metadata_helpers(): void
    {
        $widget = WidgetModel::create(['name' => 'gizmo']);

        self::assertSame('WidgetModel', $widget->getModelName());
        self::assertSame('widgets', $widget->getTableName());
        self::assertFalse($widget->isNew());
        self::assertArrayHasKey('_metadata', $widget->toArrayWithMetadata());
    }

    public function test_is_new_for_unsaved_instance(): void
    {
        self::assertTrue((new WidgetModel)->isNew());
    }

    public function test_recent_scope_filters_by_time(): void
    {
        $widget = WidgetModel::create(['name' => 'fresh']);
        WidgetModel::where('id', $widget->id)->update(['created_at' => now()->subDays(30)]);

        self::assertCount(0, WidgetModel::query()->recent(7)->get());
        self::assertCount(1, WidgetModel::query()->recent(60)->get());
    }

    public function test_reload_refreshes_attributes(): void
    {
        $widget = WidgetModel::create(['name' => 'before']);
        WidgetModel::where('id', $widget->id)->update(['name' => 'after']);

        self::assertSame('after', $widget->reload()->name);
    }

    public function test_reload_resyncs_original_so_is_modified_tells_the_truth(): void
    {
        $widget = WidgetModel::create(['name' => 'before']);
        WidgetModel::where('id', $widget->id)->update(['name' => 'after']);

        // reload() replaced the raw attributes but never re-synced $original,
        // so every reloaded attribute stayed "dirty" forever — isModified()
        // reported unsaved changes on a model freshly read from the database.
        $widget->reload();

        self::assertFalse($widget->isModified(), 'A model just reloaded from the database has no unsaved changes.');
        self::assertSame([], $widget->getDirty());
    }

    public function test_reload_reaches_a_row_hidden_by_a_global_scope(): void
    {
        $widget = ScopedWidgetModel::create(['name' => 'visible']);

        // static::query() applies global scopes, so once the row stopped
        // matching, reload() found nothing and silently kept the stale
        // in-memory values while still reporting success.
        ScopedWidgetModel::withoutGlobalScopes()
            ->where('id', $widget->id)
            ->update(['name' => 'hidden']);

        $widget->reload();

        self::assertSame('hidden', $widget->name);
    }

    public function test_reload_throws_when_the_row_is_gone(): void
    {
        $widget = WidgetModel::create(['name' => 'doomed']);
        WidgetModel::where('id', $widget->id)->delete();

        // Returning stale attributes for a deleted row is the dangerous
        // answer: the caller cannot tell a successful reload from a no-op.
        $this->expectException(ModelNotFoundException::class);

        $widget->reload();
    }
}
