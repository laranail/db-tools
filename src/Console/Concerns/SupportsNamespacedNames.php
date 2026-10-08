<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Console\Concerns;

use Simtabi\Laranail\Package\Tools\Commands\Concerns\SupportsNamespacedNames as PackageToolsSupportsNamespacedNames;

/**
 * Lets a command register a `vendor::pkg.command` name.
 *
 * Removed in 0.1.1 on the claim that nothing outside this package used it, and
 * restored in 0.1.5 because consuming applications did: their own commands
 * `use` this trait, and fataled on load without it.
 *
 * @deprecated 0.1.5 Use {@see PackageToolsSupportsNamespacedNames}, which this
 *             forwards to. Removable no earlier than 0.2.0.
 */
trait SupportsNamespacedNames
{
    use PackageToolsSupportsNamespacedNames;
}
