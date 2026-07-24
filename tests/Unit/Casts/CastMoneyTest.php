<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Casts;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Simtabi\Laranail\DbTools\Casts\CastMoney;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use stdClass;

final class MoneyModel extends Model
{
    protected $table = 'money_models';

    protected $guarded = [];

    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = ['price' => CastMoney::class.':USD'];
}

final class CastMoneyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('money_models', function ($t): void {
            $t->id();
            $t->integer('price')->nullable();
        });
    }

    private function model(): Model
    {
        return new class extends Model {};
    }

    public function test_get_converts_minor_units_to_money(): void
    {
        $money = (new CastMoney('USD'))->get($this->model(), 'price', 1234, []);

        self::assertInstanceOf(Money::class, $money);
        self::assertSame('12.34', (string) $money->getAmount());
        self::assertSame('USD', $money->getCurrency()->getCurrencyCode());
    }

    public function test_get_uses_config_default_currency_when_none_given(): void
    {
        config()->set('laranail.db-tools.money.default_currency', 'EUR');

        $money = (new CastMoney)->get($this->model(), 'price', 500, []);

        self::assertInstanceOf(Money::class, $money);
        self::assertSame('EUR', $money->getCurrency()->getCurrencyCode());
    }

    public function test_get_falls_back_to_usd_without_config(): void
    {
        config()->set('laranail.db-tools.money.default_currency');

        $money = (new CastMoney)->get($this->model(), 'price', 100, []);

        self::assertInstanceOf(Money::class, $money);
        self::assertSame('USD', $money->getCurrency()->getCurrencyCode());
    }

    public function test_set_converts_major_units_to_minor(): void
    {
        self::assertSame(1234, (new CastMoney('USD'))->set($this->model(), 'price', 12.34, []));
    }

    public function test_set_accepts_numeric_string_as_major_units(): void
    {
        self::assertSame(999, (new CastMoney('USD'))->set($this->model(), 'price', '9.99', []));
    }

    public function test_set_accepts_integer_as_major_units(): void
    {
        self::assertSame(1000, (new CastMoney('USD'))->set($this->model(), 'price', 10, []));
    }

    public function test_set_accepts_a_money_instance(): void
    {
        $money = Money::of('42.50', 'USD');

        self::assertSame(4250, (new CastMoney('USD'))->set($this->model(), 'price', $money, []));
    }

    public function test_set_rounds_half_up_without_php_floats(): void
    {
        self::assertSame(1235, (new CastMoney('USD'))->set($this->model(), 'price', '12.345', []));
    }

    public function test_null_round_trips(): void
    {
        $cast = new CastMoney('USD');

        self::assertNull($cast->get($this->model(), 'price', null, []));
        self::assertNull($cast->set($this->model(), 'price', null, []));
        self::assertNull($cast->serialize($this->model(), 'price', null, []));
    }

    public function test_set_rejects_non_numeric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[price]');

        (new CastMoney('USD'))->set($this->model(), 'price', 'not-money', []);
    }

    public function test_set_rejects_arbitrary_objects(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CastMoney('USD'))->set($this->model(), 'price', new stdClass, []);
    }

    public function test_serialize_emits_plain_array(): void
    {
        $money = Money::of('12.34', 'USD');

        self::assertSame(
            ['amount' => '12.34', 'currency' => 'USD'],
            (new CastMoney('USD'))->serialize($this->model(), 'price', $money, [])
        );
    }

    public function test_model_round_trips_through_storage(): void
    {
        $model = MoneyModel::create(['price' => 12.34]);

        self::assertSame(1234, $model->getRawOriginal('price'));

        $reloaded = MoneyModel::find($model->id);

        self::assertInstanceOf(Money::class, $reloaded->price);
        self::assertSame('12.34', (string) $reloaded->price->getAmount());
        self::assertSame('USD', $reloaded->price->getCurrency()->getCurrencyCode());
    }

    public function test_model_to_array_uses_serialized_shape(): void
    {
        $model = MoneyModel::create(['price' => 12.34]);

        self::assertSame(
            ['amount' => '12.34', 'currency' => 'USD'],
            $model->fresh()->toArray()['price']
        );
    }

    public function test_model_handles_null_price(): void
    {
        $model = MoneyModel::create(['price' => null]);

        self::assertNull($model->fresh()->price);
        self::assertNull($model->fresh()->toArray()['price']);
    }
}
