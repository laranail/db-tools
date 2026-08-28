<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Concerns\HasImmutability;
use Simtabi\Laranail\DbTools\Exceptions\ImmutableDataException;

final class AlwaysImmutableModel extends Model
{
    use HasImmutability;

    public $timestamps = false;

    protected $table = 'immutable_models';

    protected $guarded = [];
}

final class ConditionallyImmutableModel extends Model
{
    use HasImmutability;

    public bool $locked = false;

    public $timestamps = false;

    protected $table = 'immutable_models';

    protected $guarded = [];

    public function isImmutable(): bool
    {
        return $this->locked;
    }
}

final class HasImmutabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('immutable_models', function ($t): void {
            $t->id();
            $t->string('name')->nullable();
        });
    }

    public function test_creating_is_allowed(): void
    {
        $model = AlwaysImmutableModel::create(['name' => 'foo']);

        self::assertTrue($model->exists);
    }

    public function test_updating_an_immutable_model_throws(): void
    {
        $model = AlwaysImmutableModel::create(['name' => 'foo']);

        $this->expectException(ImmutableDataException::class);

        $model->update(['name' => 'bar']);
    }

    public function test_deleting_an_immutable_model_throws(): void
    {
        $model = AlwaysImmutableModel::create(['name' => 'foo']);

        $this->expectException(ImmutableDataException::class);

        $model->delete();
    }

    public function test_conditional_immutability_allows_updates_when_unlocked(): void
    {
        $model = ConditionallyImmutableModel::create(['name' => 'foo']);
        $model->locked = false;

        $model->update(['name' => 'bar']);

        self::assertSame('bar', $model->fresh()->name);
    }
}
