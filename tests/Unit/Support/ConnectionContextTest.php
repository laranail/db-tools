<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Override;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class ConnectionContextTest extends TestCase
{
    public function test_null_empty_string_and_the_default_name_share_one_key(): void
    {
        $default = (string) config('database.default');

        // The availability memo forked because `null` and the explicit default
        // name produced different keys; `''` arrives from a bare `--connection=`.
        self::assertSame($default, ConnectionContext::for(null)->key());
        self::assertSame($default, ConnectionContext::for('')->key());
        self::assertSame($default, ConnectionContext::for($default)->key());
    }

    public function test_an_explicit_name_is_its_own_key(): void
    {
        self::assertSame('secondary', ConnectionContext::for('secondary')->key());
    }

    public function test_requested_name_preserves_whether_the_caller_was_explicit(): void
    {
        self::assertNull(ConnectionContext::for(null)->requestedName());
        self::assertNull(ConnectionContext::for('')->requestedName());
        self::assertSame('secondary', ConnectionContext::for('secondary')->requestedName());
    }

    public function test_resolves_the_connection_and_its_schema_builder(): void
    {
        $context = ConnectionContext::for('secondary');

        self::assertSame('secondary', $context->connection()->getName());
        // getSchemaBuilder() hands back a fresh instance each call, so compare
        // what it is bound to rather than object identity.
        self::assertSame('secondary', $context->schema()->getConnection()->getName());
    }

    public function test_reads_config_from_the_repository_not_the_live_connection(): void
    {
        // Must work for a database that cannot be connected to at all — backup
        // and restore need the config of an unreachable host.
        config()->set('database.connections.unreachable', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'nope',
        ]);

        $context = ConnectionContext::for('unreachable');

        self::assertSame('mysql', $context->config('driver'));
        self::assertSame(1, $context->config('port'));
        self::assertSame('fallback', $context->config('missing', 'fallback'));
        self::assertIsArray($context->configArray());
    }

    public function test_config_array_is_null_for_an_unconfigured_connection(): void
    {
        self::assertNull(ConnectionContext::for('nonexistent')->configArray());
    }

    public function test_for_model_uses_the_models_own_connection(): void
    {
        $model = new ConnectionContextWidget;
        $model->setConnection('secondary');

        $context = ConnectionContext::forModel($model);

        self::assertSame('secondary', $context->key());
        self::assertSame('secondary', $context->connection()->getName());
    }

    public function test_for_model_falls_back_to_the_default_when_the_model_names_none(): void
    {
        $context = ConnectionContext::forModel(new ConnectionContextWidget);

        self::assertSame((string) config('database.default'), $context->key());
    }

    public function test_for_blueprint_reads_the_connection_the_migration_targets(): void
    {
        // The macros used the Schema facade here, so a migration on `secondary`
        // inspected the default connection instead.
        $seen = null;

        Schema::connection('secondary')->create('cc_probe', function (Blueprint $table) use (&$seen): void {
            $table->id();
            $seen = ConnectionContext::forBlueprint($table)->key();
        });

        self::assertSame('secondary', $seen);
    }

    public function test_for_blueprint_keys_to_the_default_when_the_migration_targets_it(): void
    {
        $seen = null;

        Schema::create('cc_probe_default', function (Blueprint $table) use (&$seen): void {
            $table->id();
            $seen = ConnectionContext::forBlueprint($table)->key();
        });

        self::assertSame((string) config('database.default'), $seen);
    }

    public function test_key_never_throws_without_a_config_repository(): void
    {
        // The guard's static entry points are documented usable before this
        // package's provider registers, so key() must degrade rather than throw.
        $context = ConnectionContext::for(null);

        self::assertNotSame('', $context->key());
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}

final class ConnectionContextWidget extends Model
{
    protected $table = 'cc_widgets';
}
