<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Concerns\HasNanoid;

final class HasNanoidModel extends Model
{
    use HasNanoid;

    public $timestamps = false;

    protected $table = 'has_nanoid_models';

    protected $guarded = [];
}

final class HasShortNanoidModel extends Model
{
    use HasNanoid;

    public $timestamps = false;

    protected $table = 'has_nanoid_models';

    protected $guarded = [];

    public function nanoidLength(): int
    {
        return 8;
    }
}

final class HasNanoidTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('has_nanoid_models', function ($t): void {
            $t->id();
            $t->string('nanoid')->nullable();
            $t->string('name')->nullable();
        });
    }

    public function test_auto_sets_nanoid_on_create(): void
    {
        $model = HasNanoidModel::create(['name' => 'foo']);

        self::assertNotEmpty($model->nanoid);
        self::assertSame(21, strlen((string) $model->nanoid));
    }

    public function test_uses_url_safe_alphabet(): void
    {
        $model = HasNanoidModel::create(['name' => 'foo']);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $model->nanoid);
    }

    public function test_respects_custom_length(): void
    {
        $model = HasShortNanoidModel::create(['name' => 'foo']);

        self::assertSame(8, strlen((string) $model->nanoid));
    }

    public function test_generates_unique_ids_across_many_creates(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = HasNanoidModel::create(['name' => "row-{$i}"])->nanoid;
        }

        self::assertCount(50, array_unique($ids));
    }
}
