<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Concerns\HasUuid;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class HookedUuidModel extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'hooked_uuid_models';

    protected $guarded = [];
}

final class HasUuidGeneratorHookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('hooked_uuid_models', function ($t): void {
            $t->id();
            $t->string('uuid')->nullable();
            $t->string('name')->nullable();
        });
    }

    protected function tearDown(): void
    {
        HookedUuidModel::generateUuidUsing(null);

        parent::tearDown();
    }

    public function test_custom_generator_overrides_the_default(): void
    {
        HookedUuidModel::generateUuidUsing(fn (): string => 'fixed-test-uuid');

        $model = HookedUuidModel::create(['name' => 'foo']);

        self::assertSame('fixed-test-uuid', $model->uuid);
    }

    public function test_default_generator_returns_a_valid_uuid_when_no_hook(): void
    {
        $model = HookedUuidModel::create(['name' => 'foo']);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            (string) $model->uuid,
        );
    }
}
