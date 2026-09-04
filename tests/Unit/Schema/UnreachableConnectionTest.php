<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Schema;

use Override;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Schema\DatabaseTableVerifier;
use Simtabi\Laranail\DbTools\Schema\DatabaseSchemaInspector;

/**
 * hasTable() caught every exception and answered false, so "this table does
 * not exist" and "this database is unreachable" were indistinguishable. A
 * verification run against a database that was down therefore reported
 * connected: true with every table missing — which points the operator at
 * migrations when the real problem is the connection.
 */
final class UnreachableConnectionTest extends TestCase
{
    public function test_has_table_does_not_answer_false_for_an_unreachable_database(): void
    {
        $inspector = new DatabaseSchemaInspector;

        $this->expectExceptionMessageMatches('/does not exist|unable to open database|SQLSTATE/i');

        $inspector->hasTable('widgets', 'unreachable');
    }

    public function test_verify_detailed_reports_a_down_database_as_disconnected(): void
    {
        $verifier = $this->app->make(DatabaseTableVerifier::class);

        // testConnection: false is the path that skipped the connection probe
        // and went straight to per-table checks.
        $result = $verifier->verifyDetailed(['widgets', 'gadgets'], true, false, 'unreachable');

        self::assertFalse($result['success']);
        self::assertFalse(
            $result['connected'],
            'A database that cannot be opened must not be reported as connected.',
        );
        self::assertArrayNotHasKey(
            'tables',
            $result,
            'Reporting every table "missing" advises migrating when the connection is the problem.',
        );
    }

    public function test_has_table_still_answers_false_for_a_missing_table_on_a_live_connection(): void
    {
        $inspector = new DatabaseSchemaInspector;

        self::assertFalse($inspector->hasTable('definitely_not_a_table'));
    }

    public function test_has_column_does_not_answer_false_for_an_unreachable_database(): void
    {
        $inspector = new DatabaseSchemaInspector;

        $this->expectExceptionMessageMatches('/does not exist|unable to open database|SQLSTATE/i');

        $inspector->hasColumn('widgets', 'name', 'unreachable');
    }

    public function test_has_column_still_answers_false_on_a_live_connection(): void
    {
        $inspector = new DatabaseSchemaInspector;

        self::assertFalse($inspector->hasColumn('definitely_not_a_table', 'name'));
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A directory that cannot exist, so opening the database fails at
        // connect time rather than producing an empty schema.
        $app['config']->set('database.connections.unreachable', [
            'driver'   => 'sqlite',
            'database' => '/nonexistent-' . self::class . '/does/not/exist.sqlite',
            'prefix'   => '',
        ]);
    }
}
