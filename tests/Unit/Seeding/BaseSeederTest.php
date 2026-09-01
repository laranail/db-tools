<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Seeding;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Seeding\BaseSeeder;
use Simtabi\Laranail\DbTools\Seeding\UpsertResult;
use Simtabi\Laranail\DbTools\Tests\TestCase;

/**
 * @property string $code
 * @property string $label
 */
final class SeederRole extends Model
{
    public $timestamps = false;

    protected $table = 'seeder_roles';

    protected $guarded = [];
}

final class ExampleSeeder extends BaseSeeder
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public ?UpsertResult $result = null;

    public function run(): void
    {
        $this->result = $this->upsertAll(SeederRole::class, 'code', $this->rows);
        $this->tell('info', 'seeded '.$this->result->summary());
    }

    public function callConsole(): ?object
    {
        return $this->console();
    }

    public function callBlockedInProduction(): bool
    {
        return $this->blockedInProduction();
    }
}

final class BaseSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('seeder_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('label')->nullable();
        });
    }

    // -------------------------------------------------------------------
    // The uninitialised-typed-property problem this exists for
    // -------------------------------------------------------------------

    public function test_console_is_null_when_no_command_is_driving_the_seeder(): void
    {
        // Seeder::$command is a typed, non-nullable property that Laravel
        // assigns only for console runs. Under $this->seed() it is never
        // initialised — and an uninitialised typed property is not null, so
        // `?->` does not guard it: reading it throws. Every seeder that forgets
        // the reflection check works in artisan and dies in the test suite.
        self::assertNull($this->seeder([])->callConsole());
    }

    public function test_tell_is_a_no_op_without_a_console(): void
    {
        // The point of the guard: a seeder can report progress freely without
        // every call site checking first.
        $seeder = $this->seeder([['code' => 'admin']]);

        $seeder->run();

        self::assertSame(1, $seeder->result?->created);
    }

    public function test_it_runs_under_the_seed_helper_which_is_where_the_property_is_unset(): void
    {
        // The actual reproduction. artisan db:seed sets $command; $this->seed()
        // does not, which is why this path is the one that breaks.
        $this->seed(ExampleSeeder::class);

        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------
    // upsertAll reports what happened, not what it was handed
    // -------------------------------------------------------------------

    public function test_it_counts_rows_it_created(): void
    {
        $seeder = $this->seeder([['code' => 'admin', 'label' => 'Admin']]);
        $seeder->run();
        $result = $seeder->result;

        self::assertSame(1, $result?->created);
        self::assertSame(0, $result?->updated);
        self::assertSame(0, $result?->unchanged);
    }

    public function test_a_second_identical_run_reports_nothing_changed(): void
    {
        // The method this replaces returned count($rows) — the same number on
        // every run whether it wrote anything or not, so a seeder reported
        // "40 roles" on its fortieth idempotent re-run.
        $rows = [['code' => 'admin', 'label' => 'Admin'], ['code' => 'editor', 'label' => 'Editor']];

        $firstSeeder = $this->seeder($rows);
        $firstSeeder->run();
        $first = $firstSeeder->result;

        $secondSeeder = $this->seeder($rows);
        $secondSeeder->run();
        $second = $secondSeeder->result;

        self::assertSame(2, $first?->created);
        self::assertSame(2, $second?->unchanged);
        self::assertSame(0, $second?->created);
        self::assertFalse($second?->changedAnything());
        self::assertSame('2 unchanged', $second?->summary());
    }

    public function test_it_counts_a_changed_row_as_updated(): void
    {
        $this->seeder([['code' => 'admin', 'label' => 'Admin']])->run();

        $seeder = $this->seeder([['code' => 'admin', 'label' => 'Administrator']]);
        $seeder->run();
        $result = $seeder->result;

        self::assertSame(1, $result?->updated);
        self::assertSame(0, $result?->created);
        self::assertTrue($result?->changedAnything());
    }

    public function test_it_converges_rather_than_duplicating(): void
    {
        $rows = [['code' => 'admin', 'label' => 'Admin']];

        $this->seeder($rows)->run();
        $this->seeder($rows)->run();
        $this->seeder($rows)->run();

        self::assertSame(1, SeederRole::query()->count());
    }

    public function test_a_row_with_no_identity_is_refused_rather_than_duplicated(): void
    {
        // Without the key it would be inserted afresh on every run, which is
        // the duplication this method exists to prevent.
        $this->expectExceptionMessage('missing its identity column [code]');

        $this->seeder([['label' => 'No code here']])->run();
    }

    public function test_the_summary_names_only_what_happened(): void
    {
        self::assertSame('nothing to do', new UpsertResult()->summary());
        self::assertSame('2 created', new UpsertResult(created: 2)->summary());
        self::assertSame('1 created, 3 unchanged', new UpsertResult(created: 1, unchanged: 3)->summary());
        self::assertSame(4, new UpsertResult(1, 0, 3)->total());
    }

    public function test_results_combine(): void
    {
        $combined = new UpsertResult(created: 1)->plus(new UpsertResult(updated: 2, unchanged: 3));

        self::assertSame(1, $combined->created);
        self::assertSame(2, $combined->updated);
        self::assertSame(3, $combined->unchanged);
    }

    // -------------------------------------------------------------------
    // The production guard
    // -------------------------------------------------------------------

    public function test_it_is_not_blocked_outside_production(): void
    {
        self::assertFalse($this->seeder([])->callBlockedInProduction());
    }

    public function test_it_is_blocked_on_production(): void
    {
        // Not a convenience. Demo seeders write fixtures that must never reach a
        // production installation — published passwords, and in at least one
        // real case a published TOTP secret enrolled against staff accounts
        // that can suspend or delete any customer.
        $this->app['env'] = 'production';

        try {
            self::assertTrue($this->seeder([])->callBlockedInProduction());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    private function seeder(array $rows): ExampleSeeder
    {
        $seeder = new ExampleSeeder;
        $seeder->rows = $rows;

        return $seeder;
    }
}
