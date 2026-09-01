<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Override;
use Simtabi\Laranail\DbTools\Concerns\HasExtendedModel;
use Simtabi\Laranail\DbTools\Tests\TestCase;

class ExtendedModelBase extends Model
{
    use HasExtendedModel;

    public $timestamps = false;

    protected $table = 'extended_models';

    protected $fillable = ['email', 'password'];

    protected $hidden = ['password'];

    protected $attributes = ['status' => 'active'];

    #[Override]
    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}

final class ExtendedModelChild extends ExtendedModelBase
{
    protected function additionalFillable(): array
    {
        return ['credit_limit'];
    }

    protected function additionalHidden(): array
    {
        return ['credit_limit'];
    }

    protected function additionalCasts(): array
    {
        return ['credit_limit' => 'integer'];
    }

    protected function additionalAttributes(): array
    {
        return ['credit_limit' => 2];
    }
}

/** No `$fillable`, no `$guarded` — proves the trait does not open mass assignment. */
final class ExtendedModelUnguarded extends Model
{
    use HasExtendedModel;

    public $timestamps = false;

    protected $table = 'extended_models';
}

/**
 * Declares its own `$guarded`. The earlier form of the trait declared that same
 * property, so composing this class was a fatal trait-property conflict.
 */
final class ExtendedModelOwnGuarded extends Model
{
    use HasExtendedModel;

    public $timestamps = false;

    protected $table = 'extended_models';

    protected $guarded = ['id'];
}

final class HasExtendedModelTest extends TestCase
{
    public function test_it_composes_all_four_traits(): void
    {
        $model = new ExtendedModelChild;

        self::assertSame(['email', 'password', 'credit_limit'], $model->getFillable());
        self::assertSame(['password', 'credit_limit'], $model->getHidden());
        self::assertSame('integer', $model->getCasts()['credit_limit']);
        self::assertSame(2, $model->credit_limit);
    }

    public function test_inherited_declarations_survive_alongside_the_additions(): void
    {
        $model = new ExtendedModelChild;

        self::assertSame('hashed', $model->getCasts()['password']);
        self::assertSame('active', $model->status);
    }

    public function test_it_does_not_disable_mass_assignment_protection(): void
    {
        self::assertSame(['*'], (new ExtendedModelUnguarded)->getGuarded());

        $this->expectException(MassAssignmentException::class);

        new ExtendedModelUnguarded(['email' => 'ada@example.com']);
    }

    public function test_a_model_may_declare_its_own_guarded(): void
    {
        self::assertSame(['id'], (new ExtendedModelOwnGuarded)->getGuarded());
    }
}
