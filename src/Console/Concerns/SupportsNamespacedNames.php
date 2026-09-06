<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Console\Concerns;

use Closure;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Allows a command to use the laranail `::` namespace separator as well as `:`.
 *
 * Symfony's `Command::setName()` runs `validateName()`, whose regex
 * (`^[^:]++(:[^:]++)*$`) rejects the empty segment in `::`. This trait writes
 * the private name/aliases directly, bound to Symfony's Command scope, so both
 * `laranail::db-tools.db` and `laranail:db-tools.db` are accepted.
 * Symfony's `find()` resolves an exact name before its `:`-splitting lookup, so
 * the command still dispatches.
 *
 * Re-implemented locally (rather than depending on `laranail/console`) to keep
 * this package's zero-laranail-dependency invariant.
 */
trait SupportsNamespacedNames
{
    public function setName(string $name): static
    {
        Closure::bind(function () use ($name): void {
            $this->name = $name;
        }, $this, SymfonyCommand::class)();

        return $this;
    }

    /**
     * @param iterable<int, string> $aliases
     */
    public function setAliases(iterable $aliases): static
    {
        $list = is_array($aliases) ? $aliases : iterator_to_array($aliases);

        Closure::bind(function () use ($list): void {
            $this->aliases = $list;
        }, $this, SymfonyCommand::class)();

        return $this;
    }
}
