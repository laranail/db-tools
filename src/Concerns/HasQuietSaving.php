<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

/**
 * Save a model without dispatching its events.
 *
 * Mirrors Laravel's built-in {@see Model::saveQuietly()};
 * kept as an explicit trait so the capability is opt-in and discoverable.
 */
trait HasQuietSaving
{
    /**
     * Save the model without firing any model events.
     *
     * @param array<string, mixed> $options
     */
    public function saveQuietly(array $options = []): bool
    {
        return static::withoutEvents(fn (): bool => $this->save($options));
    }
}
