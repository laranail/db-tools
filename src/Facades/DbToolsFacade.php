<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Facades;

use Illuminate\Support\Facades\Facade;
use Simtabi\Laranail\DbTools\DbTools;

class DbToolsFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DbTools::class;
    }
}
