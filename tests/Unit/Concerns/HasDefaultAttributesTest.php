<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Override;
use Simtabi\Laranail\DbTools\Concerns\HasDefaultAttributes;
use Simtabi\Laranail\DbTools\Tests\TestCase;

class DefaultAttributesBase extends Model
{
    use HasDefaultAttributes;

    public $timestamps = false;

    protected $table = 'default_attribute_models';

    protected $guarded = [];

    protected $attributes = ['status' => 'active'];
}

final class DefaultAttributesChild extends DefaultAttributesBase
{
    #[Override]
    protected function additionalAttributes(): array
    {
        return ['credit_limit' => 2, 'nickname' => null];
    }
}

final class HasDefaultAttributesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('default_attribute_models', function ($t): void {
            $t->id();
            $t->string('status')->nullable();
            $t->integer('credit_limit')->nullable();
            $t->string('nickname')->nullable();
        });
    }

    public function test_declared_defaults_are_available_before_any_save(): void
    {
        self::assertSame(2, (new DefaultAttributesChild)->credit_limit);
    }

    public function test_defaults_inherited_from_the_attributes_property_are_preserved(): void
    {
        self::assertSame('active', (new DefaultAttributesChild)->status);
    }

    public function test_the_base_model_is_unchanged(): void
    {
        self::assertNull((new DefaultAttributesBase)->credit_limit);
    }

    public function test_constructor_input_wins_over_a_default(): void
    {
        self::assertSame(9, (new DefaultAttributesChild(['credit_limit' => 9]))->credit_limit);
    }

    public function test_an_explicit_null_is_not_overwritten_by_the_default(): void
    {
        $model = new DefaultAttributesChild(['credit_limit' => null]);

        self::assertNull($model->credit_limit);
        self::assertArrayHasKey('credit_limit', $model->getAttributes());
    }

    public function test_a_null_valued_default_is_still_declared(): void
    {
        self::assertArrayHasKey('nickname', (new DefaultAttributesChild)->getAttributes());
    }

    public function test_a_new_model_is_not_dirty_because_of_its_defaults(): void
    {
        self::assertFalse((new DefaultAttributesChild)->isDirty());
        self::assertSame([], (new DefaultAttributesChild)->getDirty());
    }

    public function test_a_freshly_retrieved_model_is_not_dirty(): void
    {
        DefaultAttributesChild::create(['credit_limit' => null]);

        $retrieved = DefaultAttributesChild::query()->firstOrFail();

        self::assertFalse($retrieved->isDirty());
    }

    public function test_a_stored_null_survives_a_round_trip(): void
    {
        $id = DefaultAttributesChild::create(['credit_limit' => null])->getKey();

        self::assertNull(DefaultAttributesChild::query()->findOrFail($id)->credit_limit);
    }

    public function test_defaults_are_persisted_on_insert(): void
    {
        $model = DefaultAttributesChild::create([]);

        self::assertSame(2, $model->fresh()?->credit_limit);
    }

    public function test_hydration_does_not_retrofit_defaults_onto_unselected_columns(): void
    {
        DefaultAttributesChild::create(['credit_limit' => 7]);

        $partial = DefaultAttributesChild::query()->select('id', 'status')->firstOrFail();

        self::assertArrayNotHasKey('credit_limit', $partial->getAttributes());
    }
}
