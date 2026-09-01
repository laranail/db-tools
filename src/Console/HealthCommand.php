<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Console;

use Illuminate\Console\Command;
use Simtabi\Laranail\DbTools\Console\Concerns\ReadsOptions;
use Simtabi\Laranail\DbTools\Console\Concerns\SupportsNamespacedNames;
use Simtabi\Laranail\DbTools\Guard\Contracts\DatabaseAvailabilityInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;
use Simtabi\Laranail\DbTools\Schema\SchemaStatus;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;

/**
 * Prints the schema-readiness report for a connection: is it reachable, is it
 * migrated, which required tables are missing. Useful in CI/deploy gates and
 * for diagnosing a boot that warns "schema not ready". With --strict the exit
 * code is non-zero unless the status is `ready`.
 */
final class HealthCommand extends Command
{
    use ReadsOptions;
    use SupportsNamespacedNames;

    /** @var string */
    protected $signature = 'laranail::db-tools.health
        {--connection= : Database connection name (defaults to the app default)}
        {--tables= : Comma-separated required tables (defaults to config)}
        {--strict : Exit non-zero unless the schema is fully ready}';

    /** @var string */
    protected $description = 'Report database schema readiness (reachable | migrated | required tables).';

    public function handle(SchemaReadinessInterface $readiness, DatabaseAvailabilityInterface $guard): int
    {
        // This command exists to report the true database state, so lift any
        // boot-time suspension an app applied (e.g. while uninstalled).
        $guard->resume();

        $connection = $this->strOption('connection');

        $required = $this->listOption('tables');

        $report = $readiness->report($required, $connection);

        $this->line('Connection: '.ConnectionContext::for($report->connection)->key());
        $this->line('Status:     '.$report->status->value);
        $this->line('Reachable:  '.($report->reachable ? 'yes' : 'no'));
        $this->line('Migrated:   '.($report->hasMigrationsTable ? 'yes' : 'no'));

        if ($report->missingTables !== []) {
            $this->line('Missing:    '.implode(', ', $report->missingTables));
        }

        match ($report->status) {
            SchemaStatus::Ready => $this->info($report->message()),
            SchemaStatus::Down => $this->error($report->message()),
            default => $this->warn($report->message()),
        };

        if ($this->option('strict') && ! $report->isReady()) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
