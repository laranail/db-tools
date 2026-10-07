<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Listeners;

use Throwable;
use Illuminate\Support\Facades\Log;
use Simtabi\Laranail\DbTools\Events\SchemaReady;
use Simtabi\Laranail\DbTools\Schema\SchemaStatus;
use Simtabi\Laranail\DbTools\Events\SchemaNotReady;
use Simtabi\Laranail\DbTools\Events\DatabaseAvailable;
use Simtabi\Laranail\DbTools\Support\IssueLogThrottle;
use Simtabi\Laranail\DbTools\Events\DatabaseUnavailable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Default, opt-out listener that records database availability/readiness
 * problems to the log. Registered by {@see DbToolsServiceProvider}
 * when `laranail.db-tools.guard.log_events` is true. Apps that want their own
 * handling can disable it and listen to the events directly.
 *
 * It logs a change of state, not every check: a warning when a connection
 * becomes unavailable or not ready, a reminder at most once per
 * `guard.log_reminder_interval` seconds while it stays that way, and one info
 * line when it recovers. See {@see IssueLogThrottle}. It never throws: it runs
 * while the database is down, and possibly while the log sink is too.
 */
final readonly class LogDatabaseIssues
{
    private const string AVAILABILITY = 'availability';

    private const string SCHEMA = 'schema';

    public function __construct(
        private IssueLogThrottle $throttle,
        private ConfigRepository $config,
    ) {}

    public function handleDatabaseUnavailable(DatabaseUnavailable $event): void
    {
        $this->quietly(function () use ($event): void {
            $decision = $this->throttle->enter($event->connection, self::AVAILABILITY, 'unavailable');

            if ($decision === null) {
                return;
            }

            $context = ['connection' => $event->connection];

            if ($decision['reminder']) {
                Log::warning('[db-tools] Database connection still unavailable.', $context + $this->since($decision['since']));

                return;
            }

            Log::warning('[db-tools] Database connection unavailable.', $context);
        });
    }

    public function handleSchemaNotReady(SchemaNotReady $event): void
    {
        $this->quietly(function () use ($event): void {
            $report = $event->report;

            // An unreachable database already produced the "unavailable" line.
            if ($report->status === SchemaStatus::Down && ! $this->logsUnreachableSchema()) {
                return;
            }

            $decision = $this->throttle->enter($report->connection, self::SCHEMA, $report->status->value);

            if ($decision === null) {
                return;
            }

            if ($decision['reminder']) {
                Log::warning('[db-tools] Schema still not ready: ' . $report->message(), $report->toArray() + $this->since($decision['since']));

                return;
            }

            Log::warning('[db-tools] Schema not ready: ' . $report->message(), $report->toArray());
        });
    }

    public function handleDatabaseAvailable(DatabaseAvailable $event): void
    {
        $this->quietly(function () use ($event): void {
            $since = $this->throttle->leave($event->connection, self::AVAILABILITY);

            if ($since !== null) {
                Log::info('[db-tools] Database connection available again.', ['connection' => $event->connection] + $this->since($since));
            }
        });
    }

    public function handleSchemaReady(SchemaReady $event): void
    {
        $this->quietly(function () use ($event): void {
            $since = $this->throttle->leave($event->report->connection, self::SCHEMA);

            if ($since !== null) {
                Log::info('[db-tools] Schema ready again.', ['connection' => $event->report->connection] + $this->since($since));
            }
        });
    }

    private function logsUnreachableSchema(): bool
    {
        return (bool) $this->config->get('laranail.db-tools.guard.log_unreachable_schema', true);
    }

    /**
     * @return array{since: string, duration_seconds: int}
     */
    private function since(int $since): array
    {
        return [
            'since'            => gmdate('Y-m-d\TH:i:s\Z', $since),
            'duration_seconds' => max(0, now()->getTimestamp() - $since),
        ];
    }

    /**
     * @param callable(): void $callback
     */
    private function quietly(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // A log line about an outage must never become part of the outage.
        }
    }
}
