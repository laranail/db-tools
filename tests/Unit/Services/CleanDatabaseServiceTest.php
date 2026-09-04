<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Services;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Concerns\ManagesForeignKeyChecks;
use Simtabi\Laranail\DbTools\Exceptions\CleanDatabaseException;
use Simtabi\Laranail\DbTools\Services\Contracts\CleanDatabaseServiceInterface;

final class CleanDatabaseServiceTest extends TestCase
{
    private CleanDatabaseServiceInterface $cleaner;

    protected function setUp(): void
    {
        parent::setUp();

        // A parent/child pair with a real foreign key: truncating the parent
        // first is what a bare loop cannot survive.
        Schema::create('authors', function ($t): void {
            $t->id();
            $t->string('name');
        });

        Schema::create('books', function ($t): void {
            $t->id();
            $t->foreignId('author_id')->constrained('authors');
            $t->string('title');
        });

        Schema::create('migrations', function ($t): void {
            $t->id();
            $t->string('migration');
        });

        $this->cleaner = $this->app->make(CleanDatabaseServiceInterface::class);
    }

    // -----------------------------------------------------------------
    // Foreign keys
    // -----------------------------------------------------------------

    public function test_it_truncates_a_foreign_key_constrained_pair_in_either_order(): void
    {
        $this->seedRows();

        // Parent first — the order a bare truncation loop dies on.
        $result = $this->cleaner->truncate(['authors', 'books']);

        self::assertSame(0, $this->rows('authors'));
        self::assertSame(0, $this->rows('books'));
        self::assertSame(['authors', 'books'], $result->truncated);
    }

    public function test_foreign_key_enforcement_is_restored_afterwards(): void
    {
        $this->seedRows();

        $this->cleaner->truncate(['books', 'authors']);

        // The guard must put constraints back, or every later write in the
        // process runs unchecked.
        self::assertSame(0, $this->foreignKeyNestingLevel());

        $this->expectException(QueryException::class);

        $this->app['db']->table('books')->insert(['author_id' => 999, 'title' => 'Orphan']);
    }

    public function test_the_nesting_counter_unwinds_even_when_truncation_throws(): void
    {
        $this->seedRows();

        try {
            $this->cleaner->truncate(['authors', 'no_such_table']);
        } catch (CleanDatabaseException) {
            // expected
        }

        self::assertSame(0, $this->foreignKeyNestingLevel());
    }

    // -----------------------------------------------------------------
    // Protected tables
    // -----------------------------------------------------------------

    public function test_migrations_is_protected_by_default(): void
    {
        self::assertSame(['migrations'], $this->cleaner->protectedTables());
        self::assertTrue($this->cleaner->isProtected('migrations'));
        self::assertFalse($this->cleaner->isProtected('authors'));
    }

    public function test_naming_a_protected_table_is_refused_not_skipped(): void
    {
        $this->seedRows();

        try {
            $this->cleaner->truncate(['authors', 'migrations']);
            self::fail('Expected a CleanDatabaseException.');
        } catch (CleanDatabaseException $e) {
            self::assertSame(2201, $e->getCode());
        }

        // Nothing was truncated: the refusal happens before any write.
        self::assertSame(1, $this->rows('authors'));
        self::assertSame(1, $this->rows('migrations'));
    }

    public function test_truncate_all_skips_protected_tables_rather_than_refusing(): void
    {
        $this->seedRows();

        $result = $this->cleaner->truncateAll();

        self::assertContains('authors', $result->truncated);
        self::assertContains('books', $result->truncated);
        self::assertContains('migrations', $result->skipped);
        self::assertNotContains('migrations', $result->truncated);
        self::assertSame(1, $this->rows('migrations'), 'The migration ledger must survive a full clean.');
    }

    public function test_truncate_all_honours_an_extra_exclusion(): void
    {
        $this->seedRows();

        $result = $this->cleaner->truncateAll(['authors']);

        self::assertContains('authors', $result->skipped);
        self::assertSame(1, $this->rows('authors'));
        self::assertSame(0, $this->rows('books'));
    }

    public function test_the_protected_list_is_configurable(): void
    {
        config()->set('laranail.db-tools.clean.protected_tables', ['authors', 'migrations']);

        self::assertTrue($this->cleaner->isProtected('authors'));

        $this->seedRows();
        $result = $this->cleaner->truncateAll();

        self::assertContains('authors', $result->skipped);
        self::assertSame(1, $this->rows('authors'));
    }

    // -----------------------------------------------------------------
    // Input validation
    // -----------------------------------------------------------------

    public function test_an_empty_list_is_refused(): void
    {
        $this->expectException(CleanDatabaseException::class);
        $this->expectExceptionCode(2203);

        $this->cleaner->truncate([]);
    }

    public function test_a_list_of_blanks_is_refused(): void
    {
        $this->expectException(CleanDatabaseException::class);
        $this->expectExceptionCode(2203);

        $this->cleaner->truncate(['', '   ']);
    }

    public function test_an_unknown_table_is_refused_before_anything_is_truncated(): void
    {
        $this->seedRows();

        try {
            $this->cleaner->truncate(['authors', 'no_such_table']);
            self::fail('Expected a CleanDatabaseException.');
        } catch (CleanDatabaseException $e) {
            self::assertSame(2202, $e->getCode());
            self::assertStringContainsString('no_such_table', $e->getMessage());
        }

        self::assertSame(1, $this->rows('authors'));
    }

    public function test_duplicate_and_padded_names_are_normalized(): void
    {
        $this->seedRows();

        $result = $this->cleaner->truncate([' authors ', 'authors']);

        self::assertSame(['authors'], $result->truncated);
        self::assertSame(0, $this->rows('authors'));
    }

    // -----------------------------------------------------------------
    // Result
    // -----------------------------------------------------------------

    public function test_the_result_reports_what_happened(): void
    {
        $this->seedRows();

        $result = $this->cleaner->truncate(['books']);

        self::assertSame(1, $result->count());
        self::assertFalse($result->isEmpty());
        self::assertFalse($result->skippedAny());
        self::assertArrayHasKey('truncated', $result->toArray());
        self::assertArrayHasKey('connection', $result->toArray());
    }

    private function seedRows(): void
    {
        $author = $this->app['db']->table('authors')->insertGetId(['name' => 'Ada']);
        $this->app['db']->table('books')->insert(['author_id' => $author, 'title' => 'Notes']);
        $this->app['db']->table('migrations')->insert(['migration' => '2026_01_01_000000_create_things']);
    }

    private function rows(string $table): int
    {
        return $this->app['db']->table($table)->count();
    }

    private function foreignKeyNestingLevel(): int
    {
        $probe = new class
        {
            use ManagesForeignKeyChecks;

            public function level(): int
            {
                return $this->getForeignKeyCheckNestingLevel();
            }
        };

        return $probe->level();
    }
}
