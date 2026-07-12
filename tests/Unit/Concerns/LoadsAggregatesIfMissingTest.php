<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Concerns\LoadsAggregatesIfMissing;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class AggregateParent extends Model
{
    use LoadsAggregatesIfMissing;

    protected $table = 'aggregate_parents';

    protected $guarded = [];

    public function children(): HasMany
    {
        return $this->hasMany(AggregateChild::class, 'parent_id');
    }
}

final class AggregateChild extends Model
{
    protected $table = 'aggregate_children';

    protected $guarded = [];
}

final class LoadsAggregatesIfMissingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('aggregate_parents', function ($t): void {
            $t->id();
            $t->timestamps();
        });

        Schema::create('aggregate_children', function ($t): void {
            $t->id();
            $t->foreignId('parent_id');
            $t->integer('score')->default(0);
            $t->timestamps();
        });
    }

    private function seedParentWithChildren(int $count, array $scores = []): AggregateParent
    {
        $parent = AggregateParent::create();

        for ($i = 0; $i < $count; $i++) {
            $parent->children()->create(['score' => $scores[$i] ?? 0]);
        }

        return $parent;
    }

    public function test_load_if_missing_loads_only_absent_relations(): void
    {
        $parent = $this->seedParentWithChildren(2);

        self::assertFalse($parent->relationLoaded('children'));

        $parent->loadIfMissing('children');

        self::assertTrue($parent->relationLoaded('children'));
        self::assertCount(2, $parent->children);
    }

    public function test_load_count_if_missing_loads_when_absent(): void
    {
        $parent = $this->seedParentWithChildren(3);

        $parent->loadCountIfMissing('children');

        self::assertSame(3, (int) $parent->children_count);
    }

    public function test_load_count_if_missing_does_not_reload_present_count(): void
    {
        $parent = $this->seedParentWithChildren(3);

        // Simulate a pre-existing, deliberately wrong, count attribute.
        $parent->setAttribute('children_count', 99);

        $parent->loadCountIfMissing('children');

        // Untouched because the attribute is already present.
        self::assertSame(99, $parent->children_count);
    }

    public function test_load_aggregate_if_missing_loads_when_absent(): void
    {
        $parent = $this->seedParentWithChildren(2, [10, 5]);

        $parent->loadAggregateIfMissing('children', 'score', 'sum');

        // Eloquent aliases the aggregate as snake("{relation} {function} {column}").
        self::assertSame(15, (int) $parent->children_sum_score);
    }

    public function test_load_aggregate_if_missing_skips_present_attribute(): void
    {
        $parent = $this->seedParentWithChildren(2, [10, 5]);

        // Pre-seed the Eloquent-named aggregate attribute —
        // snake("{relation} {function} {column}") = children_sum_score. The
        // trait must detect it and skip recomputation (real sum would be 15).
        $parent->setAttribute('children_sum_score', 999);

        $parent->loadAggregateIfMissing('children', 'score', 'sum');

        self::assertSame(999, (int) $parent->children_sum_score);
    }
}
