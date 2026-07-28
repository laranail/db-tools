<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Console\Concerns;

/**
 * Normalise console input to the shapes the rest of the package expects.
 *
 * Symfony hands back `''` for a flag given without a value (`--connection=`),
 * and `null`/`array` for other shapes. `''` is not the same as "not supplied":
 * it is not a usable connection name, not a usable memo key, and not a usable
 * `database.connections.*` segment — it forks caches that are keyed on the
 * resolved connection.
 *
 * Extracted from DbToolsCommand, which already normalised correctly, so its
 * sibling commands stop each inventing their own answer.
 */
trait ReadsOptions
{
    /**
     * A string option, or null when absent or given without a value.
     */
    protected function strOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A string argument, or '' when absent.
     */
    protected function strArg(string $key): string
    {
        $value = $this->argument($key);

        return is_string($value) ? $value : '';
    }

    /**
     * A comma-separated option split into a trimmed, non-empty list.
     *
     * @return list<string>
     */
    protected function listOption(string $key): array
    {
        $value = $this->strOption($key);

        if ($value === null) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $value))));
    }
}
