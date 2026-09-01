<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Simtabi\Laranail\DbTools\Concerns\HasUuidsOrIntegerIds;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class HasUuidsOrIntegerIdsIntegerModel extends Model
{
    use HasUuidsOrIntegerIds;

    public $timestamps = false;

    protected $table = 'has_id_int_models';

    protected $guarded = [];
}

final class HasUuidsOrIntegerIdsStringModel extends Model
{
    use HasUuidsOrIntegerIds;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'has_id_string_models';

    protected $keyType = 'string';

    protected $guarded = [];
}

final class HasUuidsOrIntegerIdsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('has_id_int_models', function ($t): void {
            $t->id();
            $t->string('name')->nullable();
        });

        Schema::create('has_id_string_models', function ($t): void {
            $t->string('id')->primary();
            $t->string('name')->nullable();
        });
    }

    public function test_bigint_is_the_default_key_type(): void
    {
        $model = new HasUuidsOrIntegerIdsIntegerModel;

        self::assertSame('BIGINT', HasUuidsOrIntegerIdsIntegerModel::getTypeOfId());
        self::assertTrue(HasUuidsOrIntegerIdsIntegerModel::isUsingIntegerId());
        self::assertFalse(HasUuidsOrIntegerIdsIntegerModel::isUsingStringId());
        self::assertNull($model->newUniqueId());
        self::assertSame('int', $model->getKeyType());
        self::assertTrue($model->getIncrementing());
        self::assertFalse(HasUuidsOrIntegerIdsIntegerModel::determineIfUsingUuidsForId());
        self::assertFalse(HasUuidsOrIntegerIdsIntegerModel::determineIfUsingUlidsForId());
    }

    public function test_integer_model_does_not_assign_a_key_on_create(): void
    {
        $model = HasUuidsOrIntegerIdsIntegerModel::create(['name' => 'foo']);

        // The database auto-increments; the boot hook leaves the key alone.
        self::assertIsInt($model->getKey());
        self::assertGreaterThan(0, $model->getKey());
    }

    public function test_uuid_boolean_flag_selects_uuid_keys(): void
    {
        config(['laranail.db-tools.using_uuids_for_id' => true]);

        $model = new HasUuidsOrIntegerIdsStringModel;

        self::assertSame('UUID', HasUuidsOrIntegerIdsStringModel::getTypeOfId());
        self::assertFalse(HasUuidsOrIntegerIdsStringModel::isUsingIntegerId());
        self::assertTrue(HasUuidsOrIntegerIdsStringModel::isUsingStringId());
        self::assertTrue(HasUuidsOrIntegerIdsStringModel::determineIfUsingUuidsForId());
        self::assertSame('string', $model->getKeyType());
        self::assertFalse($model->getIncrementing());

        $generated = $model->newUniqueId();
        self::assertIsString($generated);
        self::assertSame(36, strlen($generated));
        self::assertTrue(Uuid::isValid($generated));
    }

    public function test_creating_hook_assigns_a_uuid_key(): void
    {
        config(['laranail.db-tools.using_uuids_for_id' => true]);

        $model = HasUuidsOrIntegerIdsStringModel::create(['name' => 'foo']);

        self::assertNotEmpty($model->getKey());
        self::assertSame(36, strlen((string) $model->getKey()));
        self::assertTrue(Uuid::isValid((string) $model->getKey()));
    }

    public function test_ulid_boolean_flag_selects_ulid_keys(): void
    {
        config(['laranail.db-tools.using_ulids_for_id' => true]);

        $model = new HasUuidsOrIntegerIdsStringModel;

        self::assertSame('ULID', HasUuidsOrIntegerIdsStringModel::getTypeOfId());
        self::assertTrue(HasUuidsOrIntegerIdsStringModel::determineIfUsingUlidsForId());
        self::assertSame('string', $model->getKeyType());
        self::assertFalse($model->getIncrementing());

        $generated = $model->newUniqueId();
        self::assertIsString($generated);
        self::assertSame(26, strlen($generated));
    }

    public function test_creating_hook_assigns_a_ulid_key(): void
    {
        config(['laranail.db-tools.using_ulids_for_id' => true]);

        $model = HasUuidsOrIntegerIdsStringModel::create(['name' => 'foo']);

        self::assertNotEmpty($model->getKey());
        self::assertSame(26, strlen((string) $model->getKey()));
    }

    public function test_id_type_string_form_resolves_to_uuid(): void
    {
        config(['laranail.db-tools.id_type' => 'UUID']);

        self::assertSame('UUID', HasUuidsOrIntegerIdsStringModel::getTypeOfId());
        self::assertTrue(HasUuidsOrIntegerIdsStringModel::isUsingStringId());

        $generated = (new HasUuidsOrIntegerIdsStringModel)->newUniqueId();
        self::assertIsString($generated);
        self::assertSame(36, strlen($generated));
    }

    public function test_id_type_string_form_is_uppercased(): void
    {
        config(['laranail.db-tools.id_type' => 'ulid']);

        self::assertSame('ULID', HasUuidsOrIntegerIdsStringModel::getTypeOfId());
    }

    public function test_boolean_flags_take_precedence_over_id_type(): void
    {
        config([
            'laranail.db-tools.id_type' => 'BIGINT',
            'laranail.db-tools.using_uuids_for_id' => true,
        ]);

        self::assertSame('UUID', HasUuidsOrIntegerIdsStringModel::getTypeOfId());
    }

    public function test_an_explicitly_supplied_key_is_not_overwritten(): void
    {
        // The creating hook assigned unconditionally, so a pre-generated key was
        // silently replaced. Anything that generates an id up front to wire up
        // foreign keys — seeders, importers, restores — wrote orphaned rows and
        // never learned about it.
        config(['laranail.db-tools.id_type' => 'UUID']);

        $id = (string) Uuid::uuid4();

        $model = HasUuidsOrIntegerIdsStringModel::create(['id' => $id, 'name' => 'explicit']);

        self::assertSame($id, $model->getKey());
        self::assertNotNull(HasUuidsOrIntegerIdsStringModel::find($id));
    }

    public function test_a_key_is_still_generated_when_none_is_supplied(): void
    {
        config(['laranail.db-tools.id_type' => 'UUID']);

        $model = HasUuidsOrIntegerIdsStringModel::create(['name' => 'generated']);

        self::assertIsString($model->getKey());
        self::assertSame(36, strlen($model->getKey()));
    }
}
