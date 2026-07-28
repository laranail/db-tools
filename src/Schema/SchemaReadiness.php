<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

use Illuminate\Support\Facades\Config;
use Simtabi\Laranail\DbTools\Events\SchemaNotReady;
use Simtabi\Laranail\DbTools\Guard\Contracts\DatabaseAvailabilityInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseTableVerifierInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;
use Simtabi\Laranail\DbTools\Support\SafeEvent;

/**
 * Boot-safe schema-readiness reporter. Layered on the availability guard (is the
 * connection reachable, without hanging) and the table verifier (which tables
 * are present). Never throws: an unreachable database yields a "down" report,
 * not an exception, so callers can proceed and warn.
 *
 * Reports are memoized per (connection, required-set) for the lifetime of the
 * instance, so repeated middleware/boot checks in one request stay cheap.
 */
final class SchemaReadiness implements SchemaReadinessInterface
{
    private const string MIGRATIONS_TABLE = 'migrations';

    /** @var array<string, SchemaReadinessReport> */
    private array $reports = [];

    public function __construct(
        private readonly DatabaseAvailabilityInterface $guard,
        private readonly DatabaseTableVerifierInterface $verifier,
        private readonly bool $emitEvents = true,
    ) {}

    public function report(array $requiredTables = [], ?string $connection = null): SchemaReadinessReport
    {
        $required = $this->resolveRequired($requiredTables);
        $key = $this->connectionKey($connection).'|'.implode(',', $required);

        if (array_key_exists($key, $this->reports)) {
            return $this->reports[$key];
        }

        return $this->reports[$key] = $this->build($required, $connection);
    }

    public function isReady(array $requiredTables = [], ?string $connection = null): bool
    {
        return $this->report($requiredTables, $connection)->isReady();
    }

    /**
     * Drop memoized reports so the next call re-evaluates, and flush the
     * underlying availability memo with it.
     *
     * Memoization assumes a short-lived process. In a long-lived one — Octane, a
     * queue worker, `artisan migrate` followed by more work in the same process —
     * a report taken before the schema existed would otherwise be returned
     * forever, leaving the app convinced it is un-migrated after it has been
     * migrated.
     */
    public function flush(?string $connection = null): void
    {
        if ($connection === null) {
            $this->reports = [];
        } else {
            $prefix = $this->connectionKey($connection).'|';

            foreach (array_keys($this->reports) as $key) {
                if (str_starts_with($key, $prefix)) {
                    unset($this->reports[$key]);
                }
            }
        }

        $this->guard->flush($connection);
    }

    /**
     * Normalise a connection name for keying. `null` and the explicit default
     * name address the same connection and must share one entry, or a targeted
     * flush misses the reports stored under the other form.
     */
    private function connectionKey(?string $connection): string
    {
        return ConnectionContext::for($connection)->key();
    }

    public function whenReady(callable $callback, mixed $default = null, array $requiredTables = [], ?string $connection = null): mixed
    {
        return $this->isReady($requiredTables, $connection) ? $callback() : $default;
    }

    /**
     * @param  list<string>  $required
     */
    private function build(array $required, ?string $connection): SchemaReadinessReport
    {
        if (! $this->guard->isAvailable($connection)) {
            return $this->finish(new SchemaReadinessReport(
                status: SchemaStatus::Down,
                reachable: false,
                hasMigrationsTable: false,
                missingTables: $required,
                connection: $connection,
            ));
        }

        $hasMigrations = $this->guard->hasTable(self::MIGRATIONS_TABLE, $connection);

        if (! $hasMigrations) {
            return $this->finish(new SchemaReadinessReport(
                status: SchemaStatus::Empty,
                reachable: true,
                hasMigrationsTable: false,
                missingTables: $required,
                connection: $connection,
            ));
        }

        $missing = array_values($this->verifier->getMissingTables($required, $connection));

        return $this->finish(new SchemaReadinessReport(
            status: $missing === [] ? SchemaStatus::Ready : SchemaStatus::Pending,
            reachable: true,
            hasMigrationsTable: true,
            missingTables: $missing,
            connection: $connection,
        ));
    }

    private function finish(SchemaReadinessReport $report): SchemaReadinessReport
    {
        if ($this->emitEvents && ! $report->isReady()) {
            SafeEvent::dispatch(new SchemaNotReady($report));
        }

        return $report;
    }

    /**
     * @param  list<string>  $requiredTables
     * @return list<string>
     */
    private function resolveRequired(array $requiredTables): array
    {
        if ($requiredTables !== []) {
            return array_values(array_unique($requiredTables));
        }

        $configured = Config::get('laranail.db-tools.readiness.required_tables', [self::MIGRATIONS_TABLE]);
        $configured = is_array($configured) ? $configured : [self::MIGRATIONS_TABLE];

        // The configured set always includes the migrations table so the
        // empty/pending distinction stays meaningful. An explicit caller list
        // (above) is taken verbatim — build() checks the migrations table
        // separately, so the distinction holds either way.
        return array_values(array_unique([self::MIGRATIONS_TABLE, ...$configured]));
    }
}
