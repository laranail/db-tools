<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Exceptions;

use Throwable;
use RuntimeException;

/**
 * Base exception for laranail/db-tools.
 *
 * Mirrors the LaranailException constructor surface (context + userMessage)
 * without a dependency on the laranail core package.
 */
class DbToolsException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        protected array $context = [],
        protected ?string $userMessage = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getUserMessage(): ?string
    {
        return $this->userMessage;
    }

    /** @return array<string, mixed> */
    public function toLogContext(): array
    {
        return [
            'exception'    => static::class,
            'code'         => $this->getCode(),
            'context'      => $this->context,
            'user_message' => $this->userMessage,
            'file'         => $this->getFile(),
            'line'         => $this->getLine(),
        ];
    }
}
