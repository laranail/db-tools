<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Simtabi\Laranail\DbTools\Concerns\HasUuid;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class HasUuidModel extends Model
{
    use HasUuid;

    protected $table = 'has_uuid_models';

    protected $guarded = [];

    public $timestamps = false;
}

final class HasUuidCustomColumnModel extends Model
{
    use HasUuid;

    protected $table = 'has_uuid_models';

    protected $guarded = [];

    public $timestamps = false;

    public function uuidColumn(): string
    {
        return 'order_uuid';
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
}
