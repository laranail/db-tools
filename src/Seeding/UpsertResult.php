<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Seeding;

/**
 * What a bulk upsert actually did.
 *
 * The method this replaces documented "number of rows written" and returned
 * `count($rows)` — the number of rows it was *given*, which is the same number
 * on every run whether it wrote anything or not. A seeder reporting "seeded 40
 * roles" on its fortieth idempotent re-run is reporting the size of its input.
 *
 * Distinguishing the three cases is what makes the number worth printing:
 * `created` is new work, `updated` is convergence, and `unchanged` is the
 * steady state a re-run should reach.
 */
final readonly class UpsertResult
{
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $unchanged = 0,
    ) {}

    public function total(): int
    {
        return $this->created + $this->updated + $this->unchanged;
    }

    /**
     * Whether this run changed anything at all.
     *
     * The property a second run of the same seeder should have: false.
     */
    public function changedAnything(): bool
    {
        return $this->created > 0 || $this->updated > 0;
    }

    public function plus(self $other): self
    {
        return new self(
            created: $this->created + $other->created,
            updated: $this->updated + $other->updated,
            unchanged: $this->unchanged + $other->unchanged,
        );
    }

    /**
     * A line worth printing, naming only what happened.
     */
    public function summary(): string
    {
        $parts = [];

        if ($this->created > 0) {
            $parts[] = "{$this->created} created";
        }

        if ($this->updated > 0) {
            $parts[] = "{$this->updated} updated";
        }

        if ($this->unchanged > 0) {
            $parts[] = "{$this->unchanged} unchanged";
        }

        return $parts === [] ? 'nothing to do' : implode(', ', $parts);
    }
}
