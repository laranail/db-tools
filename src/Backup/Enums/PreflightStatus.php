<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Backup\Enums;

/**
 * What a backup preflight found about the client tool a dump would run.
 */
enum PreflightStatus: string
{
    /** The tool runs, and nothing known stops it dumping this server. */
    case Ready = 'ready';

    /** The tool could not be run: not on PATH, or a configured path that does not exist. */
    case Missing = 'missing';

    /** The tool runs but is older than the server, which it cannot dump. */
    case Outdated = 'outdated';
}
