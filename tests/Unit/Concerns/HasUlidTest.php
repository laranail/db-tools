<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Symfony\Component\Uid\Ulid;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Concerns\HasUlid;

final class HasUlidModel extends Model
{
    use HasUlid;

    public $timestamps = false;

    protected $table = 'has_ulid_models';

    protected $guarded = [];
}

final class HasUlidTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('has_ulid_models', function ($t): void {
            $t->id();
            $t->string('ulid')->nullable();
            $t->string('name')->nullable();
        });
    }

    public function test_auto_sets_ulid_on_create(): void
    {
        $model = HasUlidModel::create(['name' => 'foo']);

        self::assertNotEmpty($model->ulid);
        self::assertSame(26, strlen((string) $model->ulid));
        self::assertTrue(Ulid::isValid($model->ulid));
    }

    public function test_ulids_are_lexicographically_sortable_by_creation_order(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = HasUlidModel::create(['name' => "row-{$i}"])->ulid;
            usleep(2_000); // 2ms between creations
        }

        $sorted = $ids;
        sort($sorted, SORT_STRING);

        self::assertSame($ids, $sorted, 'ULIDs should be lexicographically sortable in creation order');
    }
}
