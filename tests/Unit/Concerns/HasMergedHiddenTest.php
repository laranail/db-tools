<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Override;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Concerns\HasMergedHidden;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;

class MergedHiddenBase extends Model
{
    use HasMergedHidden;

    public $timestamps = false;

    protected $table = 'merged_hidden_models';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];
}

final class MergedHiddenChild extends MergedHiddenBase
{
    #[Override]
    protected function additionalHidden(): array
    {
        return ['two_factor_secret', 'password'];
    }
}

final class HasMergedHiddenTest extends TestCase
{
    public function test_the_base_model_is_unchanged(): void
    {
        self::assertSame(['password', 'remember_token'], (new MergedHiddenBase)->getHidden());
    }

    public function test_a_subclass_extends_the_inherited_list_without_duplicates(): void
    {
        self::assertSame(
            ['password', 'remember_token', 'two_factor_secret'],
            (new MergedHiddenChild)->getHidden(),
        );
    }

    public function test_inherited_entries_cannot_be_lost_during_serialization(): void
    {
        $model = new MergedHiddenChild([
            'email'             => 'ada@example.com',
            'password'          => TestCredentials::password('hidden'),
            'remember_token'    => TestCredentials::secret('remember'),
            'two_factor_secret' => TestCredentials::secret('authenticator'),
        ]);

        self::assertSame(['email' => 'ada@example.com'], $model->toArray());
    }
}
