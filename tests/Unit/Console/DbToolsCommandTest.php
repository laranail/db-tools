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
            'action'    => 'export',
            '--path'    => '/tmp/does-not-matter.sql',
            '--dry-run' => true,
        ])->assertExitCode(0);
    }

    public function test_clean_rejects_unknown_tables(): void
    {
        $this->artisan('laranail::db-tools.db', [
            'action'   => 'clean',
            '--tables' => 'ghost_table',
            '--force'  => true,
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
            'action'   => 'clean',
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
            'action'   => 'clean',
            '--tables' => 'clean_widgets',
            '--force'  => true,
        ])->assertExitCode(0);

        self::assertSame(0, DB::table('clean_widgets')->count());
    }

    public function test_dry_run_reports_a_destructive_action_without_confirmation(): void
    {
        Schema::create('dry_widgets', function ($t): void {
            $t->id();
        });

        // --dry-run was checked AFTER confirmDestructive(), so a dry run in a
        // non-interactive shell hit the "re-run with --force" skip and exited 0
        // having printed nothing about what it would have done. A dry run
        // destroys nothing, so it must never need confirming.
        $this->artisan('laranail::db-tools.db', [
            'action'    => 'clean',
            '--tables'  => 'dry_widgets',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('[dry-run] would truncate dry_widgets')
            ->assertExitCode(0);
    }

    public function test_a_non_interactive_skip_does_not_report_success(): void
    {
        Schema::create('chained_widgets', function ($t): void {
            $t->id();
            $t->string('name');
        });
        DB::table('chained_widgets')->insert([['name' => 'a']]);

        // The skip exited 0, so `db-tools restore && deploy` deployed against a
        // database that was never restored. Nobody declined here — there was no
        // terminal to ask — so the caller must not read this as done.
        $this->artisan('laranail::db-tools.db', [
            'action'           => 'clean',
            '--tables'         => 'chained_widgets',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('re-run with --force')
            ->assertExitCode(1);

        self::assertSame(1, DB::table('chained_widgets')->count());
    }
}
