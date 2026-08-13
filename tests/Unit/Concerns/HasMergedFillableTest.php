<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Override;
use Simtabi\Laranail\DbTools\Concerns\HasMergedFillable;
use Simtabi\Laranail\DbTools\Tests\TestCase;

class MergedFillableBase extends Model
{
    use HasMergedFillable;

    protected $table = 'merged_fillable_models';

    public $timestamps = false;

    protected $fillable = ['email', 'name'];
}

class MergedFillableChild extends MergedFillableBase
{
    #[Override]
    protected function additionalFillable(): array
    {
        return ['credit_limit'];
    }
}

final class MergedFillableGrandchild extends MergedFillableChild
{
    #[Override]
    protected function additionalFillable(): array
    {
        // Deliberately restates an inherited column to prove de-duplication.
        return [...parent::additionalFillable(), 'nickname', 'email'];
    }
}

final class HasMergedFillableTest extends TestCase
{
    public function test_the_base_model_is_unchanged(): void
    {
        self::assertSame(['email', 'name'], (new MergedFillableBase)->getFillable());
    }

    public function test_a_subclass_extends_the_inherited_list(): void
    {
        self::assertSame(
            ['email', 'name', 'credit_limit'],
            (new MergedFillableChild)->getFillable(),
        );
    }

    public function test_merging_chains_across_several_levels(): void
    {
        self::assertSame(
            ['email', 'name', 'credit_limit', 'nickname'],
            (new MergedFillableGrandchild)->getFillable(),
        );
    }

    public function test_duplicates_are_removed_and_keys_reindexed(): void
    {
        $fillable = (new MergedFillableGrandchild)->getFillable();

        self::assertSame(array_values($fillable), $fillable);
        self::assertSame(array_unique($fillable), $fillable);
    }

    public function test_the_merged_additions_are_actually_mass_assignable(): void
    {
        $model = new MergedFillableChild(['name' => 'Ada', 'credit_limit' => 5]);

        self::assertSame('Ada', $model->name);
        self::assertSame(5, $model->credit_limit);
    }

    public function test_attributes_outside_the_merged_list_are_still_rejected(): void
    {
        $model = new MergedFillableChild(['name' => 'Ada', 'is_admin' => true]);

        self::assertNull($model->is_admin);
    }
}
