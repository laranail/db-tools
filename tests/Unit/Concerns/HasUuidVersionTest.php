<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Ramsey\Uuid\Uuid;
use InvalidArgumentException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Concerns\HasUuid;
use Simtabi\Laranail\DbTools\Support\SchemaColumnCache;

final class UuidV4Model extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'uuid_version_models';

    protected $guarded = [];
}

final class UuidV5Model extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'uuid_version_models';

    protected $guarded = [];

    protected $uuidVersion = 5;

    protected $uuidString = 'invoice-2026-0001';
}

final class UuidV3Model extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'uuid_version_models';

    protected $guarded = [];

    protected $uuidVersion = 3;

    protected $uuidString = 'invoice-2026-0001';
}

final class UuidV1Model extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'uuid_version_models';

    protected $guarded = [];

    protected $uuidVersion = 1;
}

final class UuidV5NoStringModel extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'uuid_version_models';

    protected $guarded = [];

    protected $uuidVersion = 5;
}

/**
 * $uuidVersion and $uuidString are published knobs with public resolvers, but
 * getGeneratedUuid() consulted neither — every model got a random v4
 * regardless. A model configured for v5 namespace UUIDs, whose whole point is
 * that the same input yields the same id, silently got a fresh random value
 * each time, so "idempotent" re-imports inserted duplicates instead of
 * colliding on the unique index.
 */
final class HasUuidVersionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SchemaColumnCache::flush();

        Schema::create('uuid_version_models', function ($t): void {
            $t->id();
            $t->string('uuid')->nullable();
        });
    }

    public function test_default_is_still_a_random_v4(): void
    {
        $model = UuidV4Model::create([]);

        self::assertSame(4, $this->versionOf($model->uuid));
    }

    public function test_version_5_is_honoured_and_deterministic(): void
    {
        $first = UuidV5Model::create([]);
        $second = UuidV5Model::create([]);

        self::assertSame(5, $this->versionOf($first->uuid));
        self::assertSame(
            $first->uuid,
            $second->uuid,
            'A v5 UUID over the same name must be reproducible — that is the point of using it.',
        );
    }

    public function test_version_3_is_honoured_and_deterministic(): void
    {
        $first = UuidV3Model::create([]);
        $second = UuidV3Model::create([]);

        self::assertSame(3, $this->versionOf($first->uuid));
        self::assertSame($first->uuid, $second->uuid);
    }

    public function test_version_1_is_honoured(): void
    {
        $model = UuidV1Model::create([]);

        self::assertSame(1, $this->versionOf($model->uuid));
    }

    public function test_a_name_based_version_without_a_name_is_rejected(): void
    {
        // Silently falling back to a random value would defeat the only reason
        // to ask for v3 or v5.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('$uuidString');

        UuidV5NoStringModel::create([]);
    }

    private function versionOf(string $uuid): int
    {
        return Uuid::fromString($uuid)->getFields()->getVersion() ?? 0;
    }
}
