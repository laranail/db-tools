<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Facades;

use Illuminate\Support\Facades\Facade;
use Simtabi\Laranail\DbTools\DbTools;

/**
 * @method static \Simtabi\Laranail\DbTools\Guard\Contracts\DatabaseAvailabilityInterface guard()
 * @method static bool isAvailable(?string $connection = null)
 * @method static bool tableExists(string $table, ?string $connection = null)
 * @method static mixed whenAvailable(callable $callback, mixed $default = null, ?string $connection = null)
 *
 * @see \Simtabi\Laranail\DbTools\DbTools
 */
class DbToolsFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DbTools::class;
    }
}
