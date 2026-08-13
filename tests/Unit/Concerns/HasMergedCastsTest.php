<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Override;
use Simtabi\Laranail\DbTools\Concerns\HasMergedCasts;
use Simtabi\Laranail\DbTools\Tests\TestCase;

/**
 * Declares its cast map with the idiomatic Laravel 11+ `casts()` method — the
 * shape that shadows a trait-provided `casts()` override. See the trait docblock.
 */
class MergedCastsMethodBase extends Model
{
    use HasMergedCasts;

    protected $table = 'merged_casts_models';

    protected $guarded = [];

    public $timestamps = false;

    #[Override]
    protected function casts(): array
    {
        return ['flags' => 'array', 'amount' => 'integer'];
    }
}

final class MergedCastsMethodChild extends MergedCastsMethodBase
{
    #[Override]
    protected function additionalCasts(): array
    {
        return ['ratio' => 'float', 'amount' => 'string'];
    }
}

/** Declares its cast map with the older `$casts` property instead. */
class MergedCastsPropertyBase extends Model
{
    use HasMergedCasts;

    protected $table = 'merged_casts_models';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['flags' => 'array'];
}

final class MergedCastsPropertyChild extends MergedCastsPropertyBase
{
    #[Override]
    protected function additionalCasts(): array
    {
        return ['ratio' => 'float'];
    }
}

final class HasMergedCastsTest extends TestCase
{
    public function test_a_base_declaring_the_casts_method_does_not_shadow_the_merge(): void
    {
        $casts = (new MergedCastsMethodChild)->getCasts();

        self::assertSame('array', $casts['flags']);
        self::assertSame('float', $casts['ratio']);
    }

    public function test_a_base_declaring_the_casts_property_also_merges(): void
    {
        $casts = (new MergedCastsPropertyChild)->getCasts();

        self::assertSame('array', $casts['flags']);
        self::assertSame('float', $casts['ratio']);
    }

    public function test_an_addition_overrides_an_inherited_cast_for_the_same_key(): void
    {
        self::assertSame('integer', (new MergedCastsMethodBase)->getCasts()['amount']);
        self::assertSame('string', (new MergedCastsMethodChild)->getCasts()['amount']);
    }

    public function test_the_base_model_is_unchanged(): void
    {
        self::assertArrayNotHasKey('ratio', (new MergedCastsMethodBase)->getCasts());
    }

    public function test_the_merged_casts_are_applied_to_attribute_access(): void
    {
        $model = new MergedCastsMethodChild(['ratio' => '1.5', 'amount' => 42]);

        self::assertSame(1.5, $model->ratio);
        self::assertSame('42', $model->amount);
    }

    public function test_the_merged_casts_are_applied_during_serialization(): void
    {
        $model = new MergedCastsMethodChild(['ratio' => '1.5']);

        self::assertSame(1.5, $model->toArray()['ratio']);
    }
}
