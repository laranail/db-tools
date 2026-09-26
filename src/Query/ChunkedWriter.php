<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Query;

use Illuminate\Database\Query\Builder;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;

/**
 * Bulk `insertOrIgnore()` and `upsert()` in chunks.
 *
 * One multi-row statement binds `rows × columns` parameters, and every driver
 * caps that: PostgreSQL and MySQL at 65,535 placeholders, SQLite at 32,766 by
 * default. A few thousand rows of a wide table is enough to hit it, and the
 * failure arrives as a driver error far from the code that built the rows.
 * Chunking keeps each statement under the cap.
 *
 * Both methods go through Laravel's own `insertOrIgnore()` / `upsert()`, which
 * already emit the right dialect — `INSERT … ON CONFLICT DO NOTHING` on
 * PostgreSQL and SQLite, `INSERT IGNORE` on MySQL and MariaDB — so there is no
 * driver branch here, and a caller hand-writing one can delete it.
 *
 *     ChunkedWriter::for('icon_termables')->chunk(500)->insertOrIgnore($rows);
 *     ChunkedWriter::for('icons')->upsert($rows, uniqueBy: ['path'], update: ['svg', 'hash']);
 */
final class ChunkedWriter
{
    /** @var int<1, max> */
    private int $chunkSize = 500;

    private function __construct(
        private readonly string $table,
        private readonly ?string $connection,
    ) {}

    public static function for(string $table, ?string $connection = null): self
    {
        return new self($table, $connection);
    }

    public function chunk(int $size): self
    {
        $this->chunkSize = max(1, $size);

        return $this;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return int rows inserted (ignored duplicates are not counted)
     */
    public function insertOrIgnore(array $rows): int
    {
        $inserted = 0;

        foreach (array_chunk($rows, $this->chunkSize) as $chunk) {
            $inserted += $this->query()->insertOrIgnore($chunk);
        }

        return $inserted;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param non-empty-array<int, non-empty-string>|non-empty-string $uniqueBy
     * @param list<string>|null $update columns to overwrite on conflict; null = every column given
     *
     * @return int affected rows, as the driver reports them
     */
    public function upsert(array $rows, array|string $uniqueBy, ?array $update = null): int
    {
        $affected = 0;

        foreach (array_chunk($rows, $this->chunkSize) as $chunk) {
            $affected += $this->query()->upsert($chunk, $uniqueBy, $update);
        }

        return $affected;
    }

    private function query(): Builder
    {
        return ConnectionContext::for($this->connection)->connection()->table($this->table);
    }
}
