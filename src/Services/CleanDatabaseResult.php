<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Services;

use Illuminate\Contracts\Support\Arrayable;

/**
 * What a truncation run actually did.
 *
 * `skipped` is never empty by accident: it holds the protected tables a
 * whole-database clean deliberately left alone, so a caller can report them
 * rather than wonder why the row counts did not go to zero.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class CleanDatabaseResult implements Arrayable
{
    /**
     * @param list<string> $truncated
     * @param list<string> $skipped
     */
    public function __construct(
        public array $truncated,
        public array $skipped,
        public string $connection,
    ) {}

    public function count(): int
    {
        return count($this->truncated);
    }

    public function isEmpty(): bool
    {
        return $this->truncated === [];
    }

    public function skippedAny(): bool
    {
        return $this->skipped !== [];
    }

    /**
     * @return array{truncated: list<string>, skipped: list<string>, connection: string, count: int}
     */
    public function toArray(): array
    {
        return [
            'truncated'  => $this->truncated,
            'skipped'    => $this->skipped,
            'connection' => $this->connection,
            'count'      => $this->count(),
        ];
    }
}
