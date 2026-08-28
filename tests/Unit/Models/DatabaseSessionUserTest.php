<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Models;

use LogicException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Models\DatabaseSession;

final class SessionUserModel extends Model
{
    public $timestamps = false;

    protected $table = 'session_users';

    protected $guarded = [];
}

final class DatabaseSessionUserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('session_users', function ($t): void {
            $t->id();
            $t->string('name');
        });

        Schema::create('sessions', function ($t): void {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable();
            $t->text('payload')->nullable();
            $t->integer('last_activity');
        });
    }

    public function test_user_relation_resolves_the_configured_model(): void
    {
        $user = SessionUserModel::create(['name' => 'ada']);

        DatabaseSession::query()->create([
            'id'            => 'sess-1',
            'user_id'       => $user->id,
            'last_activity' => time(),
        ]);

        $session = (new DatabaseSession)->usingUserModel(SessionUserModel::class)
            ->newQuery()
            ->find('sess-1');

        self::assertInstanceOf(DatabaseSession::class, $session);

        // usingUserModel() set a plain instance property, and newFromBuilder()
        // builds a fresh instance, so the configuration never reached the
        // hydrated row. The relation then fell back to Model::class — which is
        // abstract — and every access fatalled.
        self::assertInstanceOf(SessionUserModel::class, $session->user);
        self::assertSame('ada', $session->user->name);
    }

    public function test_user_relation_falls_back_to_the_configured_auth_model(): void
    {
        config()->set('auth.providers.users.model', SessionUserModel::class);

        $user = SessionUserModel::create(['name' => 'grace']);

        DatabaseSession::query()->create([
            'id'            => 'sess-2',
            'user_id'       => $user->id,
            'last_activity' => time(),
        ]);

        $session = DatabaseSession::query()->find('sess-2');

        self::assertInstanceOf(DatabaseSession::class, $session);
        self::assertSame('grace', $session->user?->name);
    }

    public function test_user_relation_explains_itself_when_no_model_is_configured(): void
    {
        config()->set('auth.providers.users.model');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no user model');

        (new DatabaseSession)->user();
    }
}
