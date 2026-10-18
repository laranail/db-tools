<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Backup\Contracts;

use Simtabi\Laranail\DbTools\Backup\Support\BackupPreflight;

/**
 * A backup manager that can preflight a connection's backup. Separate from
 * BackupManagerInterface so an implementation written before it existed
 * still satisfies that contract; callers check for it with instanceof.
 */
interface BackupManagerPreflightInterface
{
    /**
     * Null when the connection's driver cannot preflight.
     */
    public function preflight(?string $connection = null): ?BackupPreflight;
}
