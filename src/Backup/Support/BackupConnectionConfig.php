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
 * Two more spellings Laravel's connectors accept are normalised for the
 * client tools:
 *
 * - MySQL's socket is `unix_socket` in Laravel and `socket` here; with it set
 *   PDO connects through the socket and ignores host and port.
 * - A Postgres `host` may carry further libpq keywords after a `;`, because
 *   Laravel copies it into the DSN as is and PDO turns `;` into libpq's
 *   separator. laranail/db-console writes a tunnelled server under
 *   `sslmode=verify-full` as `db.example.com;hostaddr=127.0.0.1`: libpq dials
 *   the address and verifies the certificate against the name. The name stays
 *   `host` and the address becomes `hostaddr`, which the driver hands libpq as
 *   PGHOSTADDR.
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
        $config = (new ConfigurationUrlParser)->parseConfiguration($config);

        $socket = $config['unix_socket'] ?? null;
        if (is_string($socket) && $socket !== '' && empty($config['socket'])) {
            $config['socket'] = $socket;
        }

        if (($config['driver'] ?? null) === 'pgsql') {
            return self::splitPostgresHost($config);
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function splitPostgresHost(array $config): array
    {
        $host = $config['host'] ?? null;
        if (! is_string($host) || ! str_contains($host, ';')) {
            return $config;
        }

        $parts = explode(';', $host);
        $config['host'] = trim(array_shift($parts));

        foreach ($parts as $part) {
            [$keyword, $value] = array_pad(explode('=', $part, 2), 2, '');

            // Only hostaddr: any other keyword would change more than where
            // the client connects, and an explicit key always wins.
            if (strtolower(trim($keyword)) === 'hostaddr' && trim($value) !== '' && empty($config['hostaddr'])) {
                $config['hostaddr'] = trim($value);
            }
        }

        return $config;
    }
}
