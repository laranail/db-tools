<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Simtabi\Laranail\DbTools\Schema\SchemaReadinessReport;

/**
 * Fired by {@see SchemaReadiness} whenever a
 * readiness report comes back as anything other than "ready". Carries the full
 * report so a listener can log the exact status and missing tables, surface a
 * banner, or alert an operator that migrations/seeding are still pending.
 */
final readonly class SchemaNotReady
{
    use Dispatchable;

    public function __construct(
        public SchemaReadinessReport $report,
    ) {}
}
