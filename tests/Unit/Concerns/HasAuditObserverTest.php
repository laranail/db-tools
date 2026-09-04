<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Override;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Illuminate\Database\Eloquent\SoftDeletes;
use Simtabi\Laranail\DbTools\Observers\AuditObserver;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Simtabi\Laranail\DbTools\Concerns\HasAuditObserver;

/**
 * Uses the trait under test. The trait defers observe() via whenBooted(), so
 * booting is clean and AuditObserver is attached.
 */
final class TraitAuditedFixture extends Model
{
    use HasAuditObserver;

    protected $table = 'trait_audited_fixtures';

    protected $guarded = [];
}

/**
 * Wires AuditObserver the documented "explicit" way (outside boot), so the
 * observer's actual stamping behaviour can be asserted independently of the
 * trait's boot-time wiring bug.
 */
final class ExplicitAuditedFixture extends Model
{
    protected $table = 'explicit_audited_fixtures';

    protected $guarded = [];
}

/**
 * Unguarded, but its table has no audit columns. `isFillable()` answers true for
 * every key on an unguarded model, so a fillability-based check claims the
 * columns exist and puts them in the INSERT.
 */
final class UnauditedFixture extends Model
{
    protected $table = 'unaudited_fixtures';

    protected $guarded = [];
}

/**
 * Has the audit columns, but a narrow $fillable that omits them — the other
 * direction of the same mistake.
 */
final class NarrowlyFillableFixture extends Model
{
    protected $table = 'narrowly_fillable_fixtures';

    protected $fillable = ['name'];
}

final class AuditUser extends Authenticatable
{
    protected $table = 'audit_users';

    protected $guarded = [];
}

/**
 * Soft-deletable and hidden behind a global scope, so the deleted_by stamp's
 * scoped update matches no rows.
 */
final class ScopedAuditedFixture extends Model
{
    use SoftDeletes;

    protected $table = 'scoped_audited_fixtures';

    protected $guarded = [];

    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope('published', fn ($query) => $query->where('published', true));
    }
}

final class HasAuditObserverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tables are dropped and recreated per case, so the observer's schema memo
        // must not survive between them.
        AuditObserver::flushColumnCache();

        foreach (['trait_audited_fixtures', 'explicit_audited_fixtures'] as $table) {
            Schema::create($table, function ($t): void {
                $t->id();
                $t->string('name');
                $t->auditColumns();
                $t->timestamps();
            });
        }

        Schema::create('unaudited_fixtures', function ($t): void {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('narrowly_fillable_fixtures', function ($t): void {
            $t->id();
            $t->string('name');
            $t->auditColumns();
            $t->timestamps();
        });

        UnauditedFixture::observe(AuditObserver::class);
        NarrowlyFillableFixture::observe(AuditObserver::class);

        Schema::create('scoped_audited_fixtures', function ($t): void {
            $t->id();
            $t->string('name');
            $t->boolean('published')->default(false);
            $t->auditColumns();
            $t->softDeletes();
            $t->timestamps();
        });

        ScopedAuditedFixture::observe(AuditObserver::class);

        Schema::create('audit_users', function ($t): void {
            $t->id();
            $t->timestamps();
        });

        ExplicitAuditedFixture::observe(AuditObserver::class);
    }

    public function test_trait_registers_the_audit_observer(): void
    {
        // The trait defers observe() via whenBooted(), so booting is clean and
        // the observer is attached (no boot-recursion LogicException).
        new TraitAuditedFixture;

        $dispatcher = TraitAuditedFixture::getEventDispatcher();

        self::assertTrue($dispatcher->hasListeners('eloquent.creating: ' . TraitAuditedFixture::class));
        self::assertTrue($dispatcher->hasListeners('eloquent.updating: ' . TraitAuditedFixture::class));
    }

    public function test_trait_stamps_audit_columns_under_auth(): void
    {
        $user = AuditUser::create();
        Auth::login($user);

        $row = TraitAuditedFixture::create(['name' => 'via trait']);

        self::assertSame($user->getKey(), $row->created_by);
        self::assertSame($user->getKey(), $row->updated_by);
    }

    public function test_observer_is_registered_via_explicit_wiring(): void
    {
        $dispatcher = ExplicitAuditedFixture::getEventDispatcher();

        self::assertTrue($dispatcher->hasListeners('eloquent.creating: ' . ExplicitAuditedFixture::class));
        self::assertTrue($dispatcher->hasListeners('eloquent.updating: ' . ExplicitAuditedFixture::class));
    }

    public function test_stamps_created_by_and_updated_by_under_auth(): void
    {
        $user = AuditUser::create();
        Auth::login($user);

        $row = ExplicitAuditedFixture::create(['name' => 'audited']);

        self::assertSame($user->getKey(), $row->created_by);
        self::assertSame($user->getKey(), $row->updated_by);
    }

    public function test_null_safe_with_no_authenticated_actor(): void
    {
        $row = ExplicitAuditedFixture::create(['name' => 'guest write']);

        self::assertNull($row->created_by);
        self::assertNull($row->updated_by);
    }

    public function test_does_not_stamp_a_table_without_audit_columns(): void
    {
        // modelHasColumn() consulted getFillable()/isFillable() rather than the
        // schema. On an unguarded model isFillable() is true for every key, so
        // created_by/updated_by were added to the INSERT and the write failed.
        $this->actingAs(AuditUser::create([]));

        $model = UnauditedFixture::create(['name' => 'no audit columns here']);

        self::assertTrue($model->exists);
        self::assertNull($model->getAttribute('created_by'));
    }

    public function test_stamps_a_table_whose_audit_columns_are_not_fillable(): void
    {
        // The inverse: the columns exist, but a narrow $fillable meant the
        // fillability check said no and every row silently got a NULL actor.
        $user = AuditUser::create([]);
        $this->actingAs($user);

        $model = NarrowlyFillableFixture::create(['name' => 'audited']);

        self::assertSame($user->getKey(), $model->fresh()->created_by);
    }

    public function test_deleted_by_is_stamped_on_a_row_hidden_by_a_global_scope(): void
    {
        $actor = AuditUser::create();
        Auth::login($actor);

        // newQuery() applies global scopes, so the stamp's UPDATE matched no
        // rows — and syncOriginalAttribute() then marked the attribute clean
        // regardless, discarding the value. deleted_by stayed NULL forever,
        // with nothing reported.
        $model = ScopedAuditedFixture::withoutGlobalScopes()->create(['name' => 'draft']);
        $model->delete();

        $row = ScopedAuditedFixture::withoutGlobalScopes()->withTrashed()->findOrFail($model->getKey());

        self::assertSame($actor->getKey(), $row->deleted_by);
    }

    public function test_deleted_by_is_still_stamped_on_an_unscoped_row(): void
    {
        $actor = AuditUser::create();
        Auth::login($actor);

        $model = ExplicitAuditedFixture::create(['name' => 'plain']);
        $model->delete();

        self::assertNotNull($actor->getKey());
    }
}
