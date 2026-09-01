<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Exceptions;

/**
 * UUID Exception
 *
 * Thrown when UUID-related operations fail.
 */
class UuidException extends DbToolsException
{
    /**
     * Create exception for missing UUID value
     *
     * @param  string  $columnName  The UUID column name
     */
    public static function missingValue(string $columnName): self
    {
        return new self(
            message: "UUID value for [{$columnName}] is missing.",
            code: 1001,
            context: ['column' => $columnName],
        );
    }

    /**
     * Create exception for invalid UUID format
     *
     * @param  string  $value  The invalid UUID value
     */
    public static function invalidFormat(string $value): self
    {
        return new self(
            message: "Invalid UUID format: {$value}",
            code: 1002,
            context: ['value' => $value],
        );
    }

    /**
     * Create exception for UUID generation failure
     *
     * @param  string  $reason  The failure reason
     */
    public static function generationFailed(string $reason = 'Unknown error'): self
    {
        return new self(
            message: "UUID generation failed: {$reason}",
            code: 1003,
            context: ['reason' => $reason],
        );
    }
}
