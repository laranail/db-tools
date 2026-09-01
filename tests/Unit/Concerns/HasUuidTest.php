<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Simtabi\Laranail\DbTools\Concerns\HasUuid;
use Simtabi\Laranail\DbTools\Exceptions\MissingUuidColumnException;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class HasUuidModel extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'has_uuid_models';

    protected $guarded = [];
}

final class HasUuidCustomColumnModel extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'has_uuid_models';

    protected $guarded = [];

    public function uuidColumn(): string
    {
        return 'order_uuid';
    }
}

/**
 * Points at a column the table does not have.
 */
final class HasUuidMissingColumnModel extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'has_uuid_models';

    protected $guarded = [];

    public function uuidColumn(): string
    {
        return 'missing_uuid';
    }
}

final class HasUuidTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('has_uuid_models', function ($t): void {
            $t->id();
            $t->string('uuid')->nullable();
            $t->string('order_uuid')->nullable();
            $t->string('name')->nullable();
        });
    }

    public function test_auto_sets_uuid_on_create(): void
    {
        $model = HasUuidModel::create(['name' => 'foo']);

        self::assertNotEmpty($model->uuid);
        self::assertTrue(Uuid::isValid($model->uuid));
    }

    public function test_does_not_overwrite_existing_uuid(): void
    {
        $existing = '11111111-1111-4111-8111-111111111111';
        $model = HasUuidModel::create(['name' => 'foo', 'uuid' => $existing]);

        self::assertSame($existing, $model->uuid);
    }

    public function test_uses_custom_uuid_column(): void
    {
        $model = HasUuidCustomColumnModel::create(['name' => 'foo']);

        self::assertEmpty($model->uuid);
        self::assertNotEmpty($model->order_uuid);
        self::assertTrue(Uuid::isValid($model->order_uuid));
    }

    public function test_the_uuid_column_check_is_not_repeated_per_insert(): void
    {
        // hasColumnUuid() ran a schema introspection on EVERY insert, so a
        // bulk import of 10k rows issued 10k of them. Counting queries rather
        // than checks, because one hasColumn() costs more than one query on
        // SQLite — so the property asserted is "the second insert adds none",
        // which holds whatever a single check costs.
        HasUuid::flushColumnCache();

        $introspections = 0;
        DB::listen(function ($query) use (&$introspections): void {
            if (str_contains((string) $query->sql, 'pragma') || str_contains((string) $query->sql, 'sqlite_master')) {
                $introspections++;
            }
        });

        HasUuidModel::create([]);
        $afterFirst = $introspections;

        self::assertGreaterThan(0, $afterFirst, 'The first insert must resolve the column.');

        for ($i = 0; $i < 4; $i++) {
            HasUuidModel::create([]);
        }

        self::assertSame(5, HasUuidModel::query()->count());
        self::assertSame(
            $afterFirst,
            $introspections,
            'Inserts after the first must not re-introspect the schema.',
        );
    }

    public function test_the_column_check_does_not_leak_between_columns(): void
    {
        // Two models over the SAME table looking at DIFFERENT columns must not
        // share a cache entry, or the first one checked would answer for both.
        HasUuid::flushColumnCache();

        HasUuidModel::create([]);

        $this->expectException(MissingUuidColumnException::class);
        $this->expectExceptionMessage("'missing_uuid' column");

        HasUuidMissingColumnModel::create([]);
    }
}
