<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

/**
 * Immutable snapshot of a connection's schema readiness. Returned by
 * {@see SchemaReadiness::report()}.
 *
 * Note: readiness is table-level. `ready` means every required table exists,
 * not that zero migration files are pending — counting pending migration files
 * couples to migration paths and is left to the consuming application.
 */
final readonly class SchemaReadinessReport
{
    /**
     * @param  list<string>  $missingTables  Required tables that are absent.
     */
    public function __construct(
        public SchemaStatus $status,
        public bool $reachable,
        public bool $hasMigrationsTable,
        public array $missingTables,
        public ?string $connection = null,
    ) {}

    public function isReady(): bool
    {
        return $this->status->isReady();
    }

    public function message(): string
    {
        return $this->status->message();
    }

    /**
     * @return array{status: string, reachable: bool, has_migrations_table: bool, missing_tables: list<string>, connection: string|null, message: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'reachable' => $this->reachable,
            'has_migrations_table' => $this->hasMigrationsTable,
            'missing_tables' => $this->missingTables,
            'connection' => $this->connection,
            'message' => $this->message(),
        ];
    }
}
