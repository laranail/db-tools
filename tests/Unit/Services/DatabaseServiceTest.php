<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Psr\Log\NullLogger;
use Simtabi\Laranail\DbTools\Services\DatabaseService;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class DbServiceFixture extends Model
{
    protected $table = 'db_service_fixtures';

    protected $guarded = [];

    protected $casts = ['published_at' => 'datetime'];
}

final class DbServiceRelated extends Model
{
    protected $table = 'db_service_related';

    protected $guarded = [];
}

final class DatabaseServiceTest extends TestCase
{
    private DatabaseService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DatabaseService(new NullLogger);

        Schema::create('db_service_fixtures', function ($t): void {
            $t->id();
            $t->string('name');
            $t->integer('views')->default(0);
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });

        Schema::create('db_service_related', function ($t): void {
            $t->id();
            $t->foreignId('fixture_id');
            $t->timestamps();
        });
    }

    public function test_is_joined_detects_a_join(): void
    {
        $without = DbServiceFixture::query();
        self::assertFalse($this->service->isJoined($without, 'db_service_related'));

        $with = DbServiceFixture::query()
            ->join('db_service_related', 'db_service_related.fixture_id', '=', 'db_service_fixtures.id');

        self::assertTrue($this->service->isJoined($with, 'db_service_related'));
        self::assertFalse($this->service->isJoined($with, 'unrelated'));
    }

    public function test_is_joined_returns_false_for_non_builder(): void
    {
        self::assertFalse($this->service->isJoined('not a query', 'whatever'));
    }

    public function test_modify_timestamps(): void
    {
        $model = DbServiceFixture::create(['name' => 'ts']);
        $when = now()->subYear()->startOfDay();

        $result = $this->service->modifyTimestamps(['published_at' => $when], $model);

        self::assertTrue($result);
        self::assertSame(
            $when->toDateTimeString(),
            $model->fresh()->published_at->toDateTimeString(),
        );
    }

    public function test_modify_timestamps_returns_false_for_empty_dates(): void
    {
        $model = DbServiceFixture::create(['name' => 'noop']);

        self::assertFalse($this->service->modifyTimestamps([], $model));
    }

    public function test_set_morph_class_names_merges_into_aliases(): void
    {
        config(['app.aliases' => ['Existing' => 'Existing\\Class']]);

        $this->service->setMorphClassNames(['Widget' => DbServiceFixture::class]);

        $aliases = config('app.aliases');
        self::assertSame('Existing\\Class', $aliases['Existing']);
        self::assertSame(DbServiceFixture::class, $aliases['Widget']);
    }

    public function test_generate_relationship_sync_data(): void
    {
        $data = $this->service->generateRelationshipSyncData(['7', '8', ''], ['active' => 1]);

        // Empty ids are skipped. PHP coerces numeric-string array keys to ints.
        self::assertSame([7, 8], array_keys($data));

        foreach ($data as $row) {
            self::assertArrayHasKey('id', $row);
            self::assertArrayHasKey('active', $row);
            self::assertSame(1, $row['active']);
            self::assertMatchesRegularExpression(
                '/^[0-9a-f-]{36}$/',
                $row['id'],
            );
        }
    }

    public function test_generate_relationship_sync_data_accepts_scalar_id(): void
    {
        $data = $this->service->generateRelationshipSyncData('42');

        // Single scalar id is wrapped; numeric-string key coerces to int.
        self::assertSame([42], array_keys($data));

        // A non-numeric id is preserved verbatim as the key.
        $named = $this->service->generateRelationshipSyncData('abc');
        self::assertSame(['abc'], array_keys($named));
    }

    public function test_handle_view_count_increments_once_per_session(): void
    {
        $model = DbServiceFixture::create(['name' => 'viewed', 'views' => 0]);

        self::assertTrue($this->service->handleViewCount($model, 'viewed_fixtures'));
        self::assertSame(1, (int) $model->fresh()->views);

        // Second hit in the same session is a no-op.
        self::assertFalse($this->service->handleViewCount($model, 'viewed_fixtures'));
        self::assertSame(1, (int) $model->fresh()->views);
    }

    public function test_handle_view_count_only_touches_the_viewed_row(): void
    {
        // newQuery() is UNCONSTRAINED, so incrementing through it issued
        // `UPDATE ... SET views = views + 1` with no WHERE — every row in the
        // table. A single-row fixture cannot see that; two can.
        $viewed = DbServiceFixture::create(['name' => 'viewed', 'views' => 0]);
        $other = DbServiceFixture::create(['name' => 'other', 'views' => 0]);

        self::assertTrue($this->service->handleViewCount($viewed, 'scoped_views'));

        self::assertSame(1, (int) $viewed->fresh()->views);
        self::assertSame(0, (int) $other->fresh()->views, 'A view of one row must not increment any other.');
    }
}
