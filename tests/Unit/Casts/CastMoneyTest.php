<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Casts;

use stdClass;
use Brick\Money\Money;
use InvalidArgumentException;
use Brick\Money\Context\CustomContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Casts\CastMoney;
use Simtabi\Laranail\DbTools\Exceptions\MoneyCurrencyException;

final class MoneyModel extends Model
{
    public $timestamps = false;

    protected $table = 'money_models';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['price' => CastMoney::class . ':USD'];
}

/** A multi-currency table: the amount means nothing without the row's currency. */
final class MultiCurrencyMoneyModel extends Model
{
    public $timestamps = false;

    protected $table = 'multi_currency_money_models';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['balance' => CastMoney::class . ':@currency'];
}

enum TestCurrency: string
{
    case Kes = 'KES';
    case Usd = 'USD';
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

        Schema::create('multi_currency_money_models', function ($t): void {
            $t->id();
            $t->integer('balance')->nullable();
            $t->string('currency')->nullable();
        });
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
            (new CastMoney('USD'))->serialize($this->model(), 'price', $money, []),
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
            $model->fresh()->toArray()['price'],
        );
    }

    public function test_model_handles_null_price(): void
    {
        $model = MoneyModel::create(['price' => null]);

        self::assertNull($model->fresh()->price);
        self::assertNull($model->fresh()->toArray()['price']);
    }

    // ---------------------------------------------------------------------
    // Per-row currency (:@column)
    // ---------------------------------------------------------------------

    public function test_currency_is_read_from_the_named_column(): void
    {
        $money = (new CastMoney('@currency'))
            ->get($this->model(), 'balance', 10000, ['currency' => 'KES']);

        self::assertInstanceOf(Money::class, $money);
        self::assertSame('KES', $money->getCurrency()->getCurrencyCode());
        self::assertSame('100.00', (string) $money->getAmount());
    }

    public function test_currency_column_may_hold_a_backed_enum(): void
    {
        $money = (new CastMoney('@currency'))
            ->get($this->model(), 'balance', 500, ['currency' => TestCurrency::Kes]);

        self::assertInstanceOf(Money::class, $money);
        self::assertSame('KES', $money->getCurrency()->getCurrencyCode());
    }

    public function test_an_unloaded_currency_column_throws_rather_than_defaulting(): void
    {
        config()->set('laranail.db-tools.money.default_currency', 'USD');

        $this->expectException(MoneyCurrencyException::class);
        $this->expectExceptionCode(2101);

        (new CastMoney('@currency'))->get($this->model(), 'balance', 10000, []);
    }

    public function test_a_null_currency_column_throws_rather_than_defaulting(): void
    {
        $this->expectException(MoneyCurrencyException::class);
        $this->expectExceptionCode(2102);

        (new CastMoney('@currency'))->get($this->model(), 'balance', 10000, ['currency' => null]);
    }

    public function test_a_blank_currency_column_throws(): void
    {
        $this->expectException(MoneyCurrencyException::class);
        $this->expectExceptionCode(2102);

        (new CastMoney('@currency'))->get($this->model(), 'balance', 10000, ['currency' => '  ']);
    }

    public function test_a_money_instance_contradicting_the_row_currency_is_refused(): void
    {
        $this->expectException(MoneyCurrencyException::class);
        $this->expectExceptionCode(2103);

        (new CastMoney('@currency'))
            ->set($this->model(), 'balance', Money::of('5.00', 'USD'), ['currency' => 'KES']);
    }

    public function test_a_money_instance_matching_the_row_currency_is_accepted(): void
    {
        self::assertSame(
            500,
            (new CastMoney('@currency'))
                ->set($this->model(), 'balance', Money::of('5.00', 'KES'), ['currency' => 'KES']),
        );
    }

    public function test_a_money_instance_is_accepted_before_the_currency_column_is_assigned(): void
    {
        // Attribute order is the caller's array order, so during a single
        // create() the currency may not be set yet. That is not a mismatch.
        self::assertSame(
            500,
            (new CastMoney('@currency'))->set($this->model(), 'balance', Money::of('5.00', 'USD'), []),
        );
    }

    public function test_serialize_uses_the_row_currency(): void
    {
        self::assertSame(
            ['amount' => '100.00', 'currency' => 'KES'],
            (new CastMoney('@currency'))
                ->serialize($this->model(), 'balance', 10000, ['currency' => 'KES']),
        );
    }

    public function test_a_multi_currency_row_survives_a_round_trip(): void
    {
        // The migration hazard this cast exists to prevent: a KES balance must
        // not read back as USD.
        $model = MultiCurrencyMoneyModel::create(['currency' => 'KES', 'balance' => 100.00]);

        $reloaded = MultiCurrencyMoneyModel::findOrFail($model->id);

        self::assertSame('KES', $reloaded->balance->getCurrency()->getCurrencyCode());
        self::assertSame('100.00', (string) $reloaded->balance->getAmount());
        self::assertSame(10000, $reloaded->getRawOriginal('balance'));
    }

    public function test_two_rows_may_hold_different_currencies(): void
    {
        MultiCurrencyMoneyModel::create(['currency' => 'KES', 'balance' => 100.00]);
        MultiCurrencyMoneyModel::create(['currency' => 'USD', 'balance' => 100.00]);

        $currencies = MultiCurrencyMoneyModel::query()
            ->orderBy('id')
            ->get()
            ->map(fn (Model $m): string => $m->balance->getCurrency()->getCurrencyCode())
            ->all();

        self::assertSame(['KES', 'USD'], $currencies);
    }

    public function test_updating_a_loaded_row_needs_no_particular_order(): void
    {
        // The common case: the row is hydrated, so the currency is already in
        // the attribute array and a bare number just works.
        $model = MultiCurrencyMoneyModel::create(['currency' => 'KES', 'balance' => 100.00]);

        $reloaded = MultiCurrencyMoneyModel::findOrFail($model->id);
        $reloaded->balance = 250.50;
        $reloaded->save();

        $fresh = MultiCurrencyMoneyModel::findOrFail($model->id);

        self::assertSame(25050, $fresh->getRawOriginal('balance'));
        self::assertSame('KES', $fresh->balance->getCurrency()->getCurrencyCode());
    }

    public function test_a_bare_number_before_the_currency_names_both_fixes(): void
    {
        $this->expectException(MoneyCurrencyException::class);
        $this->expectExceptionCode(2105);
        $this->expectExceptionMessage('Assign [currency] first, or assign a Money instance');

        MultiCurrencyMoneyModel::create(['balance' => 100.00, 'currency' => 'KES']);
    }

    public function test_a_money_instance_is_order_independent(): void
    {
        // Money carries its own currency, so it needs no lookup and no ordering.
        $model = MultiCurrencyMoneyModel::create([
            'balance'  => Money::of('100.00', 'KES'),
            'currency' => 'KES',
        ]);

        self::assertSame(10000, $model->getRawOriginal('balance'));
        self::assertSame('KES', $model->fresh()->balance->getCurrency()->getCurrencyCode());
    }

    // ---------------------------------------------------------------------
    // Rounding symmetry and truncation
    // ---------------------------------------------------------------------

    public function test_get_rounds_a_fractional_stored_value_rather_than_truncating(): void
    {
        // A DECIMAL column, or a driver that hands back strings, must not lose
        // the fraction to an (int) cast.
        $money = (new CastMoney('USD'))->get($this->model(), 'price', '1050.7', []);

        self::assertInstanceOf(Money::class, $money);
        self::assertSame('10.51', (string) $money->getAmount());
    }

    public function test_a_money_with_a_custom_context_rounds_instead_of_throwing(): void
    {
        // getMinorAmount() is 123.45 here; toInt() alone throws
        // RoundingNecessaryException while the equivalent numeric string
        // silently rounds. Both branches must agree.
        $money = Money::of('1.2345', 'USD', new CustomContext(4));

        self::assertSame(123, (new CastMoney('USD'))->set($this->model(), 'price', $money, []));
    }

    public function test_both_set_branches_round_the_same_way(): void
    {
        $cast = new CastMoney('USD');

        $fromNumeric = $cast->set($this->model(), 'price', '1.2345', []);
        $fromMoney = $cast->set($this->model(), 'price', Money::of('1.2345', 'USD', new CustomContext(4)), []);

        self::assertSame($fromNumeric, $fromMoney);
    }

    public function test_an_unusable_stored_value_throws_a_named_exception(): void
    {
        $this->expectException(MoneyCurrencyException::class);
        $this->expectExceptionCode(2104);

        (new CastMoney('USD'))->get($this->model(), 'price', 'not-a-number', []);
    }

    private function model(): Model
    {
        return new class extends Model {};
    }
}
