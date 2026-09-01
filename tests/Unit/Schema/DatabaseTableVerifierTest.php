<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Schema;

use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Schema\DatabaseConnectionTester;
use Simtabi\Laranail\DbTools\Schema\DatabaseSchemaInspector;
use Simtabi\Laranail\DbTools\Schema\DatabaseTableVerifier;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class DatabaseTableVerifierTest extends TestCase
{
    private DatabaseTableVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier = new DatabaseTableVerifier(
            new DatabaseConnectionTester,
            new DatabaseSchemaInspector,
        );

        Schema::create('verify_alpha', fn ($t) => $t->id());
        Schema::create('verify_beta', fn ($t) => $t->id());
    }

    public function test_verify_empty_list_passes(): void
    {
        self::assertTrue($this->verifier->verify([]));
    }

    public function test_verify_require_any(): void
    {
        self::assertTrue($this->verifier->verify(['verify_alpha', 'verify_missing']));
        self::assertFalse($this->verifier->verify(['verify_missing', 'verify_gone']));
    }

    public function test_verify_require_all(): void
    {
        self::assertTrue($this->verifier->verify(['verify_alpha', 'verify_beta'], requireAll: true));
        self::assertFalse($this->verifier->verify(['verify_alpha', 'verify_missing'], requireAll: true));
    }

    public function test_verify_detailed_reports_existing_and_missing(): void
    {
        $detail = $this->verifier->verifyDetailed(
            ['verify_alpha', 'verify_beta', 'verify_missing'],
            requireAll: true,
        );

        self::assertFalse($detail['success']);
        self::assertTrue($detail['connected']);
        self::assertSame(['verify_alpha', 'verify_beta'], $detail['tables']['existing']);
        self::assertSame(['verify_missing'], $detail['tables']['missing']);
        self::assertSame(3, $detail['tables']['stats']['total']);
        self::assertSame(2, $detail['tables']['stats']['found']);
        self::assertSame('all', $detail['requirement']);
        self::assertSame('sqlite', $detail['connection']['driver']);
    }

    public function test_verify_detailed_require_any_passes_when_one_exists(): void
    {
        $detail = $this->verifier->verifyDetailed(['verify_alpha', 'verify_missing']);

        self::assertTrue($detail['success']);
        self::assertSame('any', $detail['requirement']);
    }

    public function test_get_existing_and_missing_tables(): void
    {
        self::assertSame(
            ['verify_alpha'],
            $this->verifier->getExistingTables(['verify_alpha', 'verify_missing']),
        );

        self::assertSame(
            ['verify_missing'],
            $this->verifier->getMissingTables(['verify_alpha', 'verify_missing']),
        );
    }

    public function test_has_laravel_tables(): void
    {
        // None of the default Laravel tables exist on the bare testing schema.
        self::assertFalse($this->verifier->hasLaravelTables());

        Schema::create('migrations', fn ($t) => $t->id());

        // Non-strict: at least one of the default tables now exists.
        self::assertTrue($this->verifier->hasLaravelTables());
        // Strict: not all default tables exist.
        self::assertFalse($this->verifier->hasLaravelTables(strict: true));
    }
}
