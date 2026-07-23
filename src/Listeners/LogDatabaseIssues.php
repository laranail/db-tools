<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Listeners;

use Illuminate\Support\Facades\Log;
use Simtabi\Laranail\DbTools\Events\DatabaseUnavailable;
use Simtabi\Laranail\DbTools\Events\SchemaNotReady;

/**
 * Default, opt-out listener that records database availability/readiness
 * problems to the log. Registered by {@see DbToolsServiceProvider}
 * when `db-tools.guard.log_events` is true. Apps that want their own handling
 * can disable it and listen to the events directly.
 */
final class LogDatabaseIssues
{
    public function handleDatabaseUnavailable(DatabaseUnavailable $event): void
    {
        Log::warning('[db-tools] Database connection unavailable.', [
            'connection' => $event->connection,
        ]);
    }

    public function handleSchemaNotReady(SchemaNotReady $event): void
    {
        Log::warning('[db-tools] Schema not ready: '.$event->report->message(), $event->report->toArray());
    }
}
