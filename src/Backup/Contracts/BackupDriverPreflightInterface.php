<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Backup\Contracts;

use Simtabi\Laranail\DbTools\Backup\Support\BackupPreflight;

/**
 * A backup driver that can say, without dumping anything, whether a backup
 * of a connection could run now. Separate from BackupDriverInterface so a
 * driver written before it existed still satisfies that contract.
 */
interface BackupDriverPreflightInterface
{
    /**
     * @param array<string, mixed> $config Database connection configuration
     */
    public function preflight(array $config): BackupPreflight;
}
