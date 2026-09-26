<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Query;

use RuntimeException;
use Illuminate\Database\Connection;
use Simtabi\Laranail\DbTools\DbTools;
use Illuminate\Database\Query\Builder;
use PHPUnit\Framework\Attributes\Group;
use Illuminate\Database\Schema\Blueprint;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Illuminate\Database\Query\Grammars\Grammar;
use Simtabi\Laranail\DbTools\Query\ChunkedWriter;
use Simtabi\Laranail\DbTools\Query\PortableQuery;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;

/**
 * Portable LIKE matching, chunked bulk writes, table statistics and trigger
 * suspension.
 *
 * The `drivers` group executes against whatever DB_CONNECTION names, so the CI
 * `drivers` job runs these exact tests on PostgreSQL, MySQL and MariaDB. The SQL
 * shape tests below need no server: they compile against each grammar, which is
 * what catches a PostgreSQL-only function reaching SQLite, or a jsonb function
 * meeting a json column, without a PostgreSQL server in the loop.
 */
#[Group('drivers')]
final class PortableSqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $schema = ConnectionContext::for(null)->schema();
        $schema->dropIfExists('portable_items');
        $schema->create('portable_items', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('tags')->nullable();
        });
    }

    protected function tearDown(): void
    {
        ConnectionContext::for(null)->schema()->dropIfExists('portable_items');

        parent::tearDown();
    }

    public function test_like_escaping_makes_wildcards_literal(): void
    {
        self::assertSame('100!% sure!_really!!', PortableQuery::escapeLike('100% sure_really!'));
        self::assertSame('%a!%b%', PortableQuery::containsPattern('a%b'));
    }

    public function test_literal_like_matches_wildcards_literally_on_this_driver(): void
    {
        $this->seedItems();

        self::assertSame(['50% off'], $this->items()->whereLiteralLike('name', '%')->pluck('name')->all());
        self::assertSame(['snake_case'], $this->items()->whereLiteralLike('name', '_')->pluck('name')->all());
        self::assertSame(['Bang!'], $this->items()->whereLiteralLike('name', '!')->pluck('name')->all());
    }

    public function test_json_array_like_matches_elements_on_this_driver(): void
    {
        $this->seedItems();

        $names = $this->items()
            ->whereJsonArrayLiteralLike('tags', 'navig')
            ->orWhereLiteralLike('name', 'snake')
            ->orderBy('name')
            ->pluck('name')->all();

        self::assertSame(['Bang!', 'snake_case'], $names);
        self::assertSame([], $this->items()->whereJsonArrayLiteralLike('tags', 'no-such-tag')->pluck('name')->all());
    }

    public function test_postgres_uses_the_json_element_function_and_never_jsonb(): void
    {
        $sql = $this->compile(PostgresGrammar::class, 'pgsql');

        self::assertStringContainsString('json_array_elements_text("tags"::json)', $sql);
        self::assertStringNotContainsString('jsonb', $sql);
        self::assertStringContainsString("ESCAPE '!'", $sql);
    }

    public function test_other_drivers_never_reach_the_postgres_function(): void
    {
        foreach ([[MySqlGrammar::class, 'mysql'], [Grammar::class, 'sqlite']] as [$grammar, $driver]) {
            $sql = $this->compile($grammar, $driver);

            self::assertStringNotContainsString('json_array_elements', $sql, $driver);
            self::assertStringContainsString("LIKE ? ESCAPE '!'", $sql, $driver);
        }
    }

    public function test_chunked_insert_or_ignore_skips_duplicates_across_chunks(): void
    {
        $rows = [];
        foreach (range(1, 7) as $i) {
            $rows[] = ['name' => "Item {$i}", 'slug' => "item-{$i}", 'tags' => '[]'];
        }
        $rows[] = ['name' => 'Duplicate', 'slug' => 'item-3', 'tags' => '[]'];

        $inserted = ChunkedWriter::for('portable_items')->chunk(3)->insertOrIgnore($rows);

        self::assertSame(7, $inserted);
        self::assertSame(7, $this->items()->count());
        self::assertSame('Item 3', $this->items()->where('slug', 'item-3')->value('name'));
    }

    public function test_chunked_upsert_updates_on_the_unique_key(): void
    {
        ChunkedWriter::for('portable_items')->insertOrIgnore([['name' => 'Old', 'slug' => 'a', 'tags' => '[]']]);

        ChunkedWriter::for('portable_items')->chunk(1)->upsert([
            ['name' => 'New', 'slug' => 'a', 'tags' => '[]'],
            ['name' => 'Other', 'slug' => 'b', 'tags' => '[]'],
        ], uniqueBy: ['slug'], update: ['name']);

        self::assertSame(['a' => 'New', 'b' => 'Other'], $this->items()->orderBy('slug')->pluck('name', 'slug')->all());
    }

    public function test_table_sizes_report_missing_tables_as_null(): void
    {
        $sizes = DbTools::tableSizes(['portable_items', 'no_such_table']);

        self::assertArrayHasKey('portable_items', $sizes);
        self::assertNull($sizes['no_such_table']);

        if ($sizes['portable_items'] !== null) {
            self::assertGreaterThan(0, $sizes['portable_items']);
        }
    }

    public function test_table_sizes_are_known_on_server_drivers(): void
    {
        if (ConnectionContext::for(null)->connection()->getDriverName() === 'sqlite') {
            self::markTestSkipped('SQLite reports a size only when compiled with dbstat.');
        }

        self::assertIsInt(DbTools::tableSizes(['portable_items'])['portable_items']);
    }

    public function test_analyze_runs_on_this_driver(): void
    {
        $this->seedItems();

        DbTools::analyzeTables(['portable_items']);

        self::assertSame(4, $this->items()->count());
    }

    public function test_trigger_suspension_reports_what_actually_happened(): void
    {
        $db = ConnectionContext::for(null)->connection();

        if ($db->getDriverName() !== 'pgsql') {
            self::assertFalse(DbTools::withoutTriggers('portable_items', ['anything'], static fn (bool $s): bool => $s));

            return;
        }

        $db->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION portable_items_upper() RETURNS trigger AS $$
            BEGIN NEW.name := upper(NEW.name); RETURN NEW; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER trg_portable_upper BEFORE INSERT ON portable_items
            FOR EACH ROW EXECUTE FUNCTION portable_items_upper();
            SQL);

        $suspended = DbTools::withoutTriggers('portable_items', ['trg_portable_upper'], function (bool $s): bool {
            $this->items()->insert(['name' => 'quiet', 'slug' => 'q', 'tags' => '[]']);

            return $s;
        });
        $this->items()->insert(['name' => 'loud', 'slug' => 'l', 'tags' => '[]']);

        self::assertTrue($suspended);
        self::assertSame(['l' => 'LOUD', 'q' => 'quiet'], $this->items()->orderBy('slug')->pluck('name', 'slug')->all());
    }

    public function test_triggers_are_restored_when_the_callback_throws(): void
    {
        $db = ConnectionContext::for(null)->connection();

        if ($db->getDriverName() !== 'pgsql') {
            self::markTestSkipped('Only PostgreSQL suspends triggers.');
        }

        $db->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION portable_items_upper() RETURNS trigger AS $$
            BEGIN NEW.name := upper(NEW.name); RETURN NEW; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER trg_portable_upper BEFORE INSERT ON portable_items
            FOR EACH ROW EXECUTE FUNCTION portable_items_upper();
            SQL);

        try {
            DbTools::withoutTriggers('portable_items', ['trg_portable_upper'], static function (): never {
                throw new RuntimeException('bulk load failed');
            });
        } catch (RuntimeException) {
        }

        $this->items()->insert(['name' => 'after', 'slug' => 'a', 'tags' => '[]']);

        self::assertSame('AFTER', $this->items()->value('name'));
    }

    public function test_an_unknown_trigger_runs_the_callback_with_triggers_live(): void
    {
        if (ConnectionContext::for(null)->connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('Only PostgreSQL suspends triggers.');
        }

        self::assertFalse(DbTools::withoutTriggers('portable_items', ['no_such_trigger'], static fn (bool $s): bool => $s));
    }

    private function items(): Builder
    {
        return ConnectionContext::for(null)->connection()->table('portable_items');
    }

    private function seedItems(): void
    {
        $this->items()->insert([
            ['name' => '50% off', 'slug' => 'sale', 'tags' => json_encode(['sale', 'discount'])],
            ['name' => 'snake_case', 'slug' => 'snake', 'tags' => json_encode(['style'])],
            ['name' => 'Bang!', 'slug' => 'bang', 'tags' => json_encode(['navigation', 'menu'])],
            ['name' => 'plain', 'slug' => 'plain', 'tags' => json_encode([])],
        ]);
    }

    /**
     * @param class-string<Grammar> $grammar
     */
    private function compile(string $grammar, string $driver): string
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDriverName')->willReturn($driver);
        $connection->method('getTablePrefix')->willReturn('');

        $builder = new Builder($connection, new $grammar($connection), new Processor);

        return $builder->from('portable_items')
            ->whereJsonArrayLiteralLike('tags', 'nav')
            ->orWhereLiteralLike('name', 'nav')
            ->toSql();
    }
}
