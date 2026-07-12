<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

/**
 * Auto-cast the columns listed in `$jsonColumns` (or returned by
 * `jsonColumns()`) to array on read and JSON-encode on write.
 *
 * Use when you want json columns without explicitly setting `$casts`
 * everywhere. Designed to play nicely with Laravel's existing `$casts`
 * — values already cast by `$casts` are not double-cast here.
 *
 * Example:
 * ```
 * class Order extends Model
 * {
 *     use HasJsonColumnAccessors;
 *
 *     protected array $jsonColumns = ['metadata', 'snapshot'];
 * }
 * ```
 */
trait HasJsonColumnAccessors
{
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        if ($this->isJsonColumn($key) && is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $value : $decoded;
        }

        return $value;
    }

    public function setAttribute($key, $value)
    {
        if ($this->isJsonColumn($key) && (is_array($value) || is_object($value))) {
            $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return parent::setAttribute($key, $value);
    }

    protected function isJsonColumn(string $key): bool
    {
        $columns = method_exists($this, 'jsonColumns')
            ? $this->jsonColumns()
            : (property_exists($this, 'jsonColumns') ? $this->jsonColumns : []);

        // Skip if Laravel's $casts already handles this column — avoid
        // double-decoding.
        $casts = $this->getCasts();
        if (isset($casts[$key]) && in_array($casts[$key], ['array', 'json', 'object', 'collection'], true)) {
            return false;
        }

        return in_array($key, (array) $columns, true);
    }
}
