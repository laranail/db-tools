<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Console\Concerns;

use Simtabi\Laranail\Package\Tools\Commands\Concerns\ReadsOptions as PackageToolsReadsOptions;

/**
 * Typed accessors for a command's options and arguments.
 *
 * Removed in 0.1.1 with {@see SupportsNamespacedNames} and restored with it in
 * 0.1.5, for the same reason.
 *
 * @deprecated 0.1.5 Use {@see PackageToolsReadsOptions}, which this forwards to
 *             and which provides every method the old trait had. Removable no
 *             earlier than 0.2.0.
 */
trait ReadsOptions
{
    use PackageToolsReadsOptions;
}
