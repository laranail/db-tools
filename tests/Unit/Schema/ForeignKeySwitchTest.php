<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Schema;

use Simtabi\Laranail\DbTools\DbTools;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Group;
use Illuminate\Database\Schema\Blueprint;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Schema\ForeignKeySwitch;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;
use Simtabi\Laranail\DbTools\Exceptions\DbToolsException;

/**
 * What withoutForeignKeyChecks() actually does, per driver and per PostgreSQL
 * mode. Runs in the `drivers` group, so CI exercises it against PostgreSQL,
 * MySQL and MariaDB as well as SQLite.
 */
#[Group('drivers')]
final class ForeignKeySwitchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $schema = ConnectionContext::for(null)->schema();
        $schema->dropIfExists('fk_children');
        $schema->dropIfExists('fk_parents');
        $schema->create('fk_parents', static function (Blueprint $table): void {
            $table->id();
        });
        $schema->create('fk_children', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->constrained('fk_parents');
        });

        if ($this->driver() === 'sqlite') {
            ConnectionContext::for(null)->connection()->statement('PRAGMA foreign_keys = ON');
        }
    }

    protected function tearDown(): void
    {
        $schema = ConnectionContext::for(null)->schema();
        $schema->dropIfExists('fk_children');
        $schema->dropIfExists('fk_parents');

        parent::tearDown();
    }

    public function test_an_orphan_is_refused_outside_the_guard(): void
    {
        $this->expectException(QueryException::class);

        $this->insertOrphan();
    }

    public function test_default_mode_switches_foreign_keys_off_except_on_postgres(): void
    {
        if ($this->driver() === 'pgsql') {
            // `defer` relaxes only DEFERRABLE constraints; an ordinary foreign key
            // is still checked immediately. That is the documented default.
            $this->expectException(QueryException::class);
        }

        DbTools::withoutForeignKeyChecks(fn () => $this->insertOrphan());

        self::assertSame(1, $this->children());
    }

    public function test_replica_mode_really_switches_foreign_keys_off_on_postgres(): void
    {
        if ($this->driver() !== 'pgsql') {
            self::markTestSkipped('replica mode is PostgreSQL-only.');
        }

        config(['laranail.db-tools.foreign_keys.postgres_mode' => ForeignKeySwitch::REPLICA]);

        DbTools::withoutForeignKeyChecks(fn () => $this->insertOrphan());
        self::assertSame(1, $this->children());

        // Restored afterwards: the session is back to enforcing.
        $this->expectException(QueryException::class);
        $this->insertOrphan();
    }

    public function test_replica_mode_is_ignored_by_other_drivers(): void
    {
        if ($this->driver() === 'pgsql') {
            self::markTestSkipped('Covered by the PostgreSQL test.');
        }

        config(['laranail.db-tools.foreign_keys.postgres_mode' => ForeignKeySwitch::REPLICA]);

        DbTools::withoutForeignKeyChecks(fn () => $this->insertOrphan());

        self::assertSame(1, $this->children());
    }

    public function test_an_unknown_mode_is_a_configuration_error(): void
    {
        config(['laranail.db-tools.foreign_keys.postgres_mode' => 'off']);

        $this->expectException(DbToolsException::class);
        $this->expectExceptionMessage("must be 'defer' or 'replica'");

        ForeignKeySwitch::postgresMode();
    }

    private function insertOrphan(): void
    {
        ConnectionContext::for(null)->connection()->table('fk_children')->insert(['parent_id' => 999]);
    }

    private function children(): int
    {
        return ConnectionContext::for(null)->connection()->table('fk_children')->count();
    }

    private function driver(): string
    {
        return ConnectionContext::for(null)->connection()->getDriverName();
    }
}
