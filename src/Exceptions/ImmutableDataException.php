<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Exceptions;

use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Concerns\HasImmutability;

/**
 * Thrown when a mutating operation is attempted on an immutable model.
 *
 * @see HasImmutability
 */
class ImmutableDataException extends DbToolsException
{
    /**
     * Create the exception for the given immutable model.
     */
    public static function forModel(Model $model): self
    {
        $class = $model::class;

        return new self(
            message: "The [{$class}] model is immutable and cannot be modified or deleted.",
            code: 2001,
            context: ['model' => $class, 'key' => $model->getKey()],
        );
    }
}
