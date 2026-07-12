<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Models;

use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Models\BaseModel;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class WidgetModel extends BaseModel
{
    protected $table = 'widgets';

    protected $guarded = [];

    protected $enforceUuid = false;
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
}
