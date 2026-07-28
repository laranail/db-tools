<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Concerns\HasJsonColumnAccessors;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class JsonAccessorModel extends Model
{
    use HasJsonColumnAccessors;

    protected $table = 'json_accessor_models';

    protected $guarded = [];

    public $timestamps = false;

    /** @var list<string> */
    protected array $jsonColumns = ['metadata', 'snapshot'];
}

final class HasJsonColumnAccessorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('json_accessor_models', function ($t): void {
            $t->id();
            $t->text('metadata')->nullable();
            $t->text('snapshot')->nullable();
            $t->string('plain')->nullable();
        });
    }

    public function test_array_value_is_json_encoded_on_save(): void
    {
        $model = JsonAccessorModel::create([
            'metadata' => ['key' => 'value', 'count' => 42],
            'plain' => 'hello',
        ]);

        $raw = $model->getRawOriginal('metadata');
        self::assertSame('{"key":"value","count":42}', $raw);
    }

    public function test_string_json_is_decoded_to_array_on_read(): void
    {
        $model = JsonAccessorModel::create([
            'metadata' => ['k1' => 'v1'],
        ]);

        $reloaded = JsonAccessorModel::find($model->id);

        self::assertIsArray($reloaded->metadata);
        self::assertSame('v1', $reloaded->metadata['k1']);
    }

    public function test_non_json_columns_pass_through_unchanged(): void
    {
        $model = JsonAccessorModel::create(['plain' => 'hello world']);

        self::assertSame('hello world', $model->plain);
    }

    public function test_invalid_json_string_returns_raw_string(): void
    {
        // Bypass the setter by writing directly via the query builder.
        DB::table('json_accessor_models')->insert(['metadata' => 'not-json']);

        $model = JsonAccessorModel::first();

        self::assertSame('not-json', $model->metadata);
    }

    public function test_json_columns_are_decoded_in_array_and_json_output(): void
    {
        // Decoding happened only in getAttribute(), which toArray() does not
        // go through — so $model->metadata was an array while
        // $model->toArray()['metadata'] was still the raw JSON string. An API
        // resource built from toArray() shipped a double-encoded field.
        $model = JsonAccessorModel::create(['metadata' => ['a' => 1, 'b' => ['c' => 2]]]);
        $fresh = JsonAccessorModel::query()->findOrFail($model->getKey());

        self::assertSame(['a' => 1, 'b' => ['c' => 2]], $fresh->metadata);
        self::assertSame(['a' => 1, 'b' => ['c' => 2]], $fresh->toArray()['metadata']);

        $decoded = json_decode($fresh->toJson(), true);
        self::assertSame(['a' => 1, 'b' => ['c' => 2]], $decoded['metadata']);
    }

    public function test_non_json_columns_are_untouched_in_array_output(): void
    {
        $model = JsonAccessorModel::create(['metadata' => ['a' => 1], 'plain' => 'kept']);
        $fresh = JsonAccessorModel::query()->findOrFail($model->getKey());

        self::assertSame('kept', $fresh->toArray()['plain']);
    }
}
