<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Backup;

use DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use ReflectionMethod;
use RuntimeException;
use Simtabi\Laranail\DbTools\Backup\SqlFileRestorer;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class SqlFileRestorerTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * @return array<int, string>
     */
    private function parse(string $sql): array
    {
        $method = new ReflectionMethod(SqlFileRestorer::class, 'parseStatements');

        /** @var array<int, string> $result */
        $result = $method->invoke(new SqlFileRestorer, $sql);

        return $result;
    }

    public function test_parse_splits_on_semicolons(): void
    {
        $statements = $this->parse('SELECT 1; SELECT 2; SELECT 3');

        self::assertSame(['SELECT 1', 'SELECT 2', 'SELECT 3'], $statements);
    }

    public function test_parse_strips_single_line_comments(): void
    {
        $sql = "-- a leading comment\nSELECT 1; -- trailing comment\nSELECT 2;";

        $statements = $this->parse($sql);

        self::assertSame(['SELECT 1', 'SELECT 2'], $statements);
    }

    public function test_parse_strips_block_comments(): void
    {
        $sql = "/* block\n comment ; with a semicolon */ SELECT 1; SELECT 2;";

        $statements = $this->parse($sql);

        self::assertSame(['SELECT 1', 'SELECT 2'], $statements);
    }

    public function test_parse_does_not_split_semicolon_inside_string_literal(): void
    {
        $statements = $this->parse("INSERT INTO t VALUES ('a; b'); SELECT 1;");

        self::assertSame(["INSERT INTO t VALUES ('a; b')", 'SELECT 1'], $statements);
    }

    public function test_parse_does_not_split_inside_dollar_dollar_quoting(): void
    {
        $sql = <<<'SQL'
        CREATE FUNCTION f() RETURNS void AS $$
        BEGIN
            PERFORM 1; PERFORM 2;
        END;
        $$ LANGUAGE plpgsql;
        SELECT 1;
        SQL;

        $statements = $this->parse($sql);

        self::assertCount(2, $statements);
        self::assertStringContainsString('PERFORM 1; PERFORM 2;', $statements[0]);
        self::assertStringContainsString('$$ LANGUAGE plpgsql', $statements[0]);
        self::assertSame('SELECT 1', $statements[1]);
    }

    public function test_parse_does_not_split_inside_tagged_dollar_quoting(): void
    {
        $sql = <<<'SQL'
        CREATE FUNCTION g() RETURNS void AS $body$
            DECLARE x int;
            BEGIN x := 1; END;
        $body$ LANGUAGE plpgsql;
        SELECT 42;
        SQL;

        $statements = $this->parse($sql);

        self::assertCount(2, $statements);
        self::assertStringContainsString('x := 1; END;', $statements[0]);
        self::assertStringContainsString('$body$ LANGUAGE plpgsql', $statements[0]);
        self::assertSame('SELECT 42', $statements[1]);
    }

    public function test_restore_executes_statements_against_sqlite(): void
    {
        Schema::create('sql_restore_target', function ($t): void {
            $t->integer('id');
            $t->string('name');
        });

        $path = $this->writeTempSql(
            "INSERT INTO sql_restore_target (id, name) VALUES (1, 'alpha');\n"
            ."INSERT INTO sql_restore_target (id, name) VALUES (2, 'beta');\n"
        );

        $result = (new SqlFileRestorer)->restore($path);

        self::assertTrue($result);
        self::assertSame(2, DB::table('sql_restore_target')->count());
    }

    public function test_restore_rolls_back_on_a_bad_statement(): void
    {
        Schema::create('sql_restore_rollback', function ($t): void {
            $t->integer('id');
        });

        $path = $this->writeTempSql(
            "INSERT INTO sql_restore_rollback (id) VALUES (1);\n"
            ."INSERT INTO this_table_does_not_exist (id) VALUES (2);\n"
        );

        try {
            (new SqlFileRestorer)->restore($path);
            self::fail('Expected a RuntimeException for the bad statement.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Database restoration failed', $e->getMessage());
        }

        // The first insert must have been rolled back with the failed batch.
        self::assertSame(0, DB::table('sql_restore_rollback')->count());
    }

    public function test_restore_rejects_an_empty_file(): void
    {
        $path = $this->writeTempSql("-- only a comment\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no executable statements');

        (new SqlFileRestorer)->restore($path);
    }

    private function writeTempSql(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dbt-restorer-').'.sql';
        File::put($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
