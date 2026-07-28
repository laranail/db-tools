<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint as IlluminateBlueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Override;
use Throwable;

/**
 * BlueprintMacros — extended Blueprint with configurable ID types and
 * driver-specific setup. Subclass and pass to a custom Schema builder.
 */
class BlueprintMacros extends IlluminateBlueprint
{
    private static ?Closure $idTypeResolver = null;

    private static array $driverSetup = [];

    /**
     * Set custom ID type resolver
     */
    public static function setIdTypeResolver(Closure $resolver): void
    {
        self::$idTypeResolver = $resolver;
    }

    /**
     * Register driver-specific setup callback
     */
    public static function registerDriverSetup(string $driver, Closure $setup): void
    {
        self::$driverSetup[$driver] = $setup;
    }

    public function __construct(Connection $connection, $table, ?Closure $callback = null)
    {
        parent::__construct($connection, $table, $callback);

        $driver = $connection->getDriverName();
        if (isset(self::$driverSetup[$driver])) {
            try {
                (self::$driverSetup[$driver])($connection);
            } catch (Throwable $e) {
                // Best-effort setup, but never swallow silently: surface the
                // failure so a broken driver-setup callback is debuggable.
                error_log(sprintf(
                    '[laranail/db-tools] driver setup for "%s" failed: %s',
                    $driver,
                    $e->getMessage(),
                ));
            }
        }
    }

    #[Override]
    public function id($column = 'id'): ColumnDefinition
    {
        $idType = self::$idTypeResolver instanceof Closure
            ? (self::$idTypeResolver)()
            : 'BIGINT';

        return match ($idType) {
            'UUID' => $this->uuid($column)->primary(),
            'ULID' => $this->ulid($column)->primary(),
            default => parent::id($column),
        };
    }

    #[Override]
    public function foreignId($column): ColumnDefinition
    {
        $idType = self::$idTypeResolver instanceof Closure
            ? (self::$idTypeResolver)()
            : 'BIGINT';

        return match ($idType) {
            'UUID' => $this->foreignUuid($column),
            'ULID' => $this->foreignUlid($column),
            default => parent::foreignId($column),
        };
    }

    #[Override]
    public function morphs($name, $indexName = null, $after = null): void
    {
        $idType = self::$idTypeResolver instanceof Closure
            ? (self::$idTypeResolver)()
            : 'BIGINT';

        // uuidMorphs()/ulidMorphs() accept $after just as morphs() does, but
        // it used to be dropped on these two paths — so a migration placing
        // morph columns at a chosen position silently got them appended, on
        // exactly the id types this class exists to support.
        match ($idType) {
            'UUID' => $this->uuidMorphs($name, $indexName, $after),
            'ULID' => $this->ulidMorphs($name, $indexName, $after),
            default => parent::morphs($name, $indexName, $after),
        };
    }

    #[Override]
    public function nullableMorphs($name, $indexName = null, $after = null): void
    {
        $idType = self::$idTypeResolver instanceof Closure
            ? (self::$idTypeResolver)()
            : 'BIGINT';

        match ($idType) {
            'UUID' => $this->nullableUuidMorphs($name, $indexName, $after),
            'ULID' => $this->nullableUlidMorphs($name, $indexName, $after),
            default => parent::nullableMorphs($name, $indexName, $after),
        };
    }
}
