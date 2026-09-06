<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Override;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Concerns\HasQuietSaving;

final class QuietSavingModel extends Model
{
    use HasQuietSaving;

    public static int $savedEvents = 0;

    public $timestamps = false;

    protected $table = 'quiet_models';

    protected $guarded = [];

    #[Override]
    protected static function booted(): void
    {
        self::saved(function (): void {
            self::$savedEvents++;
        });
    }
}

final class HasQuietSavingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        QuietSavingModel::$savedEvents = 0;

        Schema::create('quiet_models', function ($t): void {
            $t->id();
            $t->string('name')->nullable();
        });
    }

    public function test_normal_save_fires_events(): void
    {
        QuietSavingModel::create(['name' => 'foo']);

        self::assertSame(1, QuietSavingModel::$savedEvents);
    }

    public function test_quiet_save_suppresses_events(): void
    {
        $model = new QuietSavingModel(['name' => 'foo']);

        $result = $model->saveQuietly();

        self::assertTrue($result);
        self::assertSame(0, QuietSavingModel::$savedEvents);
        self::assertTrue($model->exists);
    }
}
