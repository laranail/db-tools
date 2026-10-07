<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Simtabi\Laranail\DbTools\Schema\SchemaReadinessReport;

/**
 * Fired by {@see SchemaReadiness} whenever a readiness report comes back
 * "ready". The counterpart of {@see SchemaNotReady}: the default log listener
 * uses it to record that a not-ready schema has recovered.
 */
final readonly class SchemaReady
{
    use Dispatchable;

    public function __construct(
        public SchemaReadinessReport $report,
    ) {}
}
