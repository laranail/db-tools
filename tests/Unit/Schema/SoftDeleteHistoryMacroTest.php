<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class SoftDeleteHistoryMacroTest extends TestCase
{
    public function test_soft_delete_history_builds_expected_columns(): void
    {
        Schema::create('sdh_columns', function (Blueprint $t): void {
            $t->softDeleteHistory();
        });

        foreach ([
            'id',
            'record_id',
            'record_type',
            'action',
            'actor_id',
            'reason',
            'happened_at',
            'created_at',
            'updated_at',
        ] as $column) {
            self::assertTrue(
                Schema::hasColumn('sdh_columns', $column),
                "Expected soft-delete history table to have a '{$column}' column.",
            );
        }
    }

    public function test_record_morphs_default_to_integer_id(): void
    {
        config()->set('laranail.db-tools.id_type', 'BIGINT');
        config()->set('laranail.db-tools.using_uuids_for_id', false);
        config()->set('laranail.db-tools.using_ulids_for_id', false);

        Schema::create('sdh_int', function (Blueprint $t): void {
            $t->softDeleteHistory();
        });

        self::assertSame('integer', Schema::getColumnType('sdh_int', 'record_id'));
    }

    public function test_record_morphs_use_uuid_when_configured(): void
    {
        config()->set('laranail.db-tools.using_uuids_for_id', true);

        Schema::create('sdh_uuid', function (Blueprint $t): void {
            $t->softDeleteHistory();
        });

        self::assertTrue(Schema::hasColumn('sdh_uuid', 'record_id'));
        self::assertNotSame('integer', Schema::getColumnType('sdh_uuid', 'record_id'));
    }

    public function test_published_table_name_follows_config(): void
    {
        // The macro itself does not name the table — the migration that uses it
        // does, reading config('laranail.db-tools.soft_delete_history.table'). We
        // mirror that resolution here and assert the macro builds onto it.
        config()->set('laranail.db-tools.soft_delete_history.table', 'custom_undo_log');

        $table = (string) config('laranail.db-tools.soft_delete_history.table');
        self::assertSame('custom_undo_log', $table);

        Schema::create($table, function (Blueprint $t): void {
            $t->softDeleteHistory();
        });

        self::assertTrue(Schema::hasTable('custom_undo_log'));
        self::assertTrue(Schema::hasColumn('custom_undo_log', 'record_id'));
        self::assertTrue(Schema::hasColumn('custom_undo_log', 'happened_at'));
    }

    public function test_actor_id_and_reason_are_nullable(): void
    {
        Schema::create('sdh_nullable', function (Blueprint $t): void {
            $t->softDeleteHistory();
        });

        $connection = Schema::getConnection();
        $columns = collect($connection->getSchemaBuilder()->getColumns('sdh_nullable'))
            ->keyBy('name');

        self::assertTrue($columns['actor_id']['nullable']);
        self::assertTrue($columns['reason']['nullable']);
    }

    public function test_actor_id_tracks_configured_id_type(): void
    {
        config()->set('laranail.db-tools.using_uuids_for_id', false);
        config()->set('laranail.db-tools.using_ulids_for_id', false);
        config()->set('laranail.db-tools.id_type', 'BIGINT');
        Schema::create('sdh_actor_int', function (Blueprint $t): void {
            $t->softDeleteHistory();
        });
        self::assertSame('integer', Schema::getColumnType('sdh_actor_int', 'actor_id'));

        config()->set('laranail.db-tools.using_uuids_for_id', true);
        Schema::create('sdh_actor_uuid', function (Blueprint $t): void {
            $t->softDeleteHistory();
        });
        self::assertNotSame('integer', Schema::getColumnType('sdh_actor_uuid', 'actor_id'));
    }
}
