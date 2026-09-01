<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Models\DatabaseSession;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class DatabaseSessionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sessions', function ($t): void {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable();
            $t->text('payload');
            $t->integer('last_activity');
        });

        DB::table('sessions')->insert([
            'id' => 'sess-1',
            'user_id' => 7,
            'payload' => base64_encode(serialize(['_token' => 'abc', 'locale' => 'en'])),
            'last_activity' => 1_700_000_000,
        ]);
    }

    public function test_payload_is_safely_unserialized(): void
    {
        $session = DatabaseSession::query()->find('sess-1');

        self::assertSame(['_token' => 'abc', 'locale' => 'en'], $session->unserialized_payload);
    }

    public function test_last_activity_is_a_carbon_instance(): void
    {
        $session = DatabaseSession::query()->find('sess-1');

        self::assertInstanceOf(Carbon::class, $session->last_activity_at);
        self::assertSame(1_700_000_000, $session->last_activity_at->getTimestamp());
    }

    public function test_table_and_user_model_are_configurable(): void
    {
        $session = new DatabaseSession;

        self::assertSame('sessions', $session->getTable());
        self::assertSame('custom_sessions', $session->usingTable('custom_sessions')->getTable());

        $related = $session->usingUserModel(SessionUser::class)->user();
        self::assertSame(SessionUser::class, $related->getRelated()::class);
    }
}

final class SessionUser extends Model
{
    protected $table = 'users';
}
