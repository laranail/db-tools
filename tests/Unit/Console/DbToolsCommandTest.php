<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Console;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class DbToolsCommandTest extends TestCase
{
    public function test_unknown_action_fails(): void
    {
        $this->artisan('laranail::db-tools.db', ['action' => 'nope'])
            ->assertExitCode(1);
    }

    public function test_missing_path_fails(): void
    {
        $this->artisan('laranail::db-tools.db', ['action' => 'export'])
            ->assertExitCode(1);
    }

    public function test_dry_run_export_succeeds_without_touching_db(): void
    {
        $this->artisan('laranail::db-tools.db', [
            'action' => 'export',
            '--path' => '/tmp/does-not-matter.sql',
            '--dry-run' => true,
        ])->assertExitCode(0);
    }

    public function test_clean_rejects_unknown_tables(): void
    {
        $this->artisan('laranail::db-tools.db', [
            'action' => 'clean',
            '--tables' => 'ghost_table',
            '--force' => true,
        ])->assertExitCode(1);
    }

    public function test_clean_is_skipped_when_the_user_declines_confirmation(): void
    {
        Schema::create('keep_widgets', function ($t): void {
            $t->id();
            $t->string('name');
        });
        DB::table('keep_widgets')->insert([['name' => 'a'], ['name' => 'b']]);

        $this->artisan('laranail::db-tools.db', [
            'action' => 'clean',
            '--tables' => 'keep_widgets',
        ])
            ->expectsConfirmation('About to TRUNCATE keep_widgets. Continue?', 'no')
            ->assertExitCode(0);

        // Declined → the action is skipped and the data is preserved (rather than
        // silently truncated).
        self::assertSame(2, DB::table('keep_widgets')->count());
    }

    public function test_clean_truncates_named_tables(): void
    {
        Schema::create('clean_widgets', function ($t): void {
            $t->id();
            $t->string('name');
        });
        DB::table('clean_widgets')->insert([['name' => 'a'], ['name' => 'b']]);

        self::assertSame(2, DB::table('clean_widgets')->count());

        $this->artisan('laranail::db-tools.db', [
            'action' => 'clean',
            '--tables' => 'clean_widgets',
            '--force' => true,
        ])->assertExitCode(0);

        self::assertSame(0, DB::table('clean_widgets')->count());
    }
}
