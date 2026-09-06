<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Casts;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Timezone-aware datetime cast. Values are stored in UTC and presented in the
 * display timezone — the cast argument, else `app.timezone`, else `UTC`.
 *
 * Usage: `protected $casts = ['published_at' => CastDatetime::class.':Europe/Paris'];`
 *
 * @implements CastsAttributes<CarbonInterface|null, string|null>
 */
class CastDatetime implements CastsAttributes
{
    public function __construct(private readonly ?string $timezone = null) {}

    /**
     * Read a stored UTC value as a Carbon instance in the display timezone.
     *
     * @param array<string, mixed> $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonInterface
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value, 'UTC')->setTimezone($this->displayTimezone());
    }

    /**
     * Normalize an incoming value to a UTC datetime string for storage.
     *
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $datetime = $value instanceof CarbonInterface
            ? $value
            : Carbon::parse($value, $this->displayTimezone());

        return $datetime->copy()->setTimezone('UTC')->toDateTimeString();
    }

    /**
     * The timezone values are presented in.
     */
    private function displayTimezone(): string
    {
        if ($this->timezone !== null) {
            return $this->timezone;
        }

        return function_exists('config') ? (string) config('app.timezone', 'UTC') : 'UTC';
    }
}
