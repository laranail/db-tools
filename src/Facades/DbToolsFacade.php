<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Facades;

use Closure;
use Simtabi\Laranail\DbTools\DbTools;
use Illuminate\Support\Facades\Facade;
use Simtabi\Laranail\DbTools\Schema\SchemaReadinessReport;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;
use Simtabi\Laranail\DbTools\Guard\Contracts\DatabaseAvailabilityInterface;

/**
 * @method static DatabaseAvailabilityInterface guard()
 * @method static bool isAvailable(?string $connection = null)
 * @method static bool tableExists(string $table, ?string $connection = null)
 * @method static mixed whenAvailable(callable $callback, mixed $default = null, ?string $connection = null)
 * @method static mixed whenTable(string $table, callable $callback, mixed $default = null, ?string $connection = null)
 * @method static DatabaseAvailabilityInterface suspend()
 * @method static DatabaseAvailabilityInterface resume()
 * @method static mixed withoutForeignKeyChecks(Closure $callback, ?string $connection = null)
 * @method static SchemaReadinessInterface schemaReadiness()
 * @method static SchemaReadinessReport schemaReport(array $requiredTables = [], ?string $connection = null)
 *
 * @see DbTools
 */
class DbToolsFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DbTools::class;
    }
}
