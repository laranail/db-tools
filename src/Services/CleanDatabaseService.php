<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Simtabi\Laranail\DbTools\Concerns\ManagesForeignKeyChecks;
use Simtabi\Laranail\DbTools\Exceptions\CleanDatabaseException;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseTableVerifierInterface;
use Simtabi\Laranail\DbTools\Services\Contracts\CleanDatabaseServiceInterface;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;

/**
 * Truncate tables without leaving the database half-emptied.
 *
 * ## Foreign keys
 *
 * A bare truncation loop breaks the moment two of the named tables reference
 * each other: `TRUNCATE users` with a `posts.user_id` foreign key fails on
 * MySQL and on PostgreSQL without `CASCADE`, and a loop that dies halfway has
 * already emptied everything before it. Constraints are disabled for the whole
 * run — via {@see ManagesForeignKeyChecks}, which is nesting- and
 * exception-safe and keyed per connection — so table order stops mattering,
 * including for circular references, which have no valid order at all.
 *
 * ## The transaction, and its honest limit
 *
 * The run is wrapped in a transaction, which makes it atomic on PostgreSQL and
 * SQLite. It does **not** on MySQL/MariaDB: `TRUNCATE` is DDL there and forces
 * an implicit commit, so each table is final as it completes. The wrapper is
 * still worth having for the drivers where it works, and this note exists so
 * nobody assumes a rollback that the engine will not give them.
 *
 * ## Protected tables
 *
 * `laranail.db-tools.clean.protected_tables` (default `migrations`) is the
 * guard. Truncating `migrations` strands the schema at an unknown version with
 * no record of how it got there, and the confirmation prompt is not a guard at
 * all — `--force` removes it, and `--force` is what CI uses.
 */
final class CleanDatabaseService implements CleanDatabaseServiceInterface
{
    use ManagesForeignKeyChecks;

    private const string PROTECTED_CONFIG_KEY = 'laranail.db-tools.clean.protected_tables';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly DatabaseTableVerifierInterface $verifier,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function truncate(array $tables, ?string $connection = null): CleanDatabaseResult
    {
        $tables = $this->normalize($tables);

        if ($tables === []) {
            throw CleanDatabaseException::nothingRequested();
        }

        $context = ConnectionContext::for($connection);

        $missing = $this->verifier->getMissingTables($tables, $connection);

        if ($missing !== []) {
            throw CleanDatabaseException::unknownTables(array_values($missing), $context->key());
        }

        foreach ($tables as $table) {
            if ($this->isProtected($table)) {
                throw CleanDatabaseException::protectedTable($table, self::PROTECTED_CONFIG_KEY);
            }
        }

        return $this->run($tables, [], $connection);
    }

    /**
     * {@inheritDoc}
     */
    public function truncateAll(array $except = [], ?string $connection = null): CleanDatabaseResult
    {
        // Unqualified names: getTableListing() is schema-qualified by default
        // ("main.authors" on SQLite, "public.users" on PostgreSQL), which no
        // exclusion list would ever match and no ->table() call wants.
        $all = $this->normalize(
            ConnectionContext::for($connection)->schema()->getTableListing(schemaQualified: false)
        );

        $excluded = $this->normalize([...$except, ...$this->protectedTables()]);

        $targets = array_values(array_diff($all, $excluded));
        $skipped = array_values(array_intersect($all, $excluded));

        return $this->run($targets, $skipped, $connection);
    }

    /**
     * {@inheritDoc}
     */
    public function protectedTables(): array
    {
        $configured = $this->config->get(self::PROTECTED_CONFIG_KEY, ['migrations']);

        return is_array($configured) ? $this->normalize($configured) : ['migrations'];
    }

    /**
     * {@inheritDoc}
     */
    public function isProtected(string $table): bool
    {
        return in_array(trim($table), $this->protectedTables(), true);
    }

    /**
     * Truncate the resolved targets with constraints off, inside a transaction.
     *
     * @param  list<string>  $targets
     * @param  list<string>  $skipped
     */
    private function run(array $targets, array $skipped, ?string $connection): CleanDatabaseResult
    {
        $context = ConnectionContext::for($connection);

        if ($targets === []) {
            return new CleanDatabaseResult([], $skipped, $context->key());
        }

        $db = $context->connection();

        $this->withoutForeignKeyChecks(function () use ($db, $targets): void {
            $db->transaction(function () use ($db, $targets): void {
                foreach ($targets as $table) {
                    $db->table($table)->truncate();
                }
            });
        }, $connection);

        return new CleanDatabaseResult($targets, $skipped, $context->key());
    }

    /**
     * Trim, drop blanks, de-duplicate, re-index.
     *
     * @param  array<int|string, mixed>  $tables
     * @return list<string>
     */
    private function normalize(array $tables): array
    {
        $clean = [];

        foreach ($tables as $table) {
            if (! is_string($table)) {
                continue;
            }

            $trimmed = trim($table);

            if ($trimmed !== '' && ! in_array($trimmed, $clean, true)) {
                $clean[] = $trimmed;
            }
        }

        return $clean;
    }
}
