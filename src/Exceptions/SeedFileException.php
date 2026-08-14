<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Exceptions;

use Simtabi\Laranail\DbTools\Seeding\Concerns\InteractsWithSeedFiles;

/**
 * Thrown when a seeder cannot get at its fixtures or its generator.
 *
 * @see InteractsWithSeedFiles
 */
class SeedFileException extends DbToolsException
{
    /**
     * `fakerphp/faker` is not installed.
     *
     * Thrown rather than handled. The implementation this replaces ran
     * `composer install` from inside the method and then called `exit(1)` when
     * that did not help — from a library, in whatever context it happened to be
     * called from, taking the process with it. A seeder running inside a queue
     * worker or a test suite would simply vanish.
     */
    public static function missingFaker(): self
    {
        return new self(
            'fakerphp/faker is not installed, so no generator can be created. '
            .'Run `composer require --dev fakerphp/faker`.',
        );
    }

    public static function fileMissing(string $path): self
    {
        return new self(sprintf('Seed fixture not found: %s', $path));
    }

    public static function notAJsonArray(string $path): self
    {
        return new self(sprintf(
            'Seed fixture %s does not decode to a JSON array.',
            $path,
        ));
    }
}
