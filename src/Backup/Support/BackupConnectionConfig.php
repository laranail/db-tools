<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Backup\Support;

use Illuminate\Support\ConfigurationUrlParser;

/**
 * Resolves a connection's config the way Laravel does before it opens PDO.
 *
 * `database.connections.*.url` (DB_URL, or whatever the app wires to it) wins
 * over the discrete `host`/`port`/`database`/`username`/`password` keys, and
 * its scheme picks the driver. A managed provider hands out exactly that URL,
 * so a dump built from the raw keys targets `127.0.0.1` — the wrong server, or
 * none — while the application itself talks to the cluster.
 *
 * The parser pulls `url` out of the array, so resolving twice is a no-op.
 */
final class BackupConnectionConfig
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function resolve(array $config): array
    {
        return (new ConfigurationUrlParser)->parseConfiguration($config);
    }
}
