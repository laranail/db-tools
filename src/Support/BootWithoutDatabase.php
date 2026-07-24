<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Support;

use Illuminate\Support\Facades\Config;

/**
 * Make the application bootable before its database exists.
 *
 * A fresh install (or a restored-but-unmigrated instance) has no schema, so any
 * database-backed session/cache/queue store fails while booting the very page
 * that would create those tables. This swaps those drivers to filesystem/sync
 * equivalents for the current process only — nothing is written to .env.
 *
 * The swap map is config-driven ({@see Config('laranail.db-tools.boot_without_database')})
 * and each entry only fires when the live value matches the "from" driver, so a
 * deployment already on redis/file is left untouched.
 */
final class BootWithoutDatabase
{
    /**
     * The built-in fallback map, used when config is unavailable.
     *
     * @var array<string, array<string, string>>
     */
    private const array DEFAULT_MAP = [
        'session.driver' => ['database' => 'file'],
        'cache.default' => ['database' => 'file'],
    ];

    /**
     * Swap database-backed drivers to their filesystem/sync equivalents.
     *
     * @param  array<string, array<string, string>>|null  $map  Override map: {config key: [from => to]}
     * @return array<string, string> The keys actually changed, mapped to their new value.
     */
    public static function degradeToFilesystem(?array $map = null): array
    {
        $map ??= self::configuredMap();

        $changed = [];

        foreach ($map as $key => $swaps) {
            $current = Config::get($key);

            if (is_string($current) && isset($swaps[$current])) {
                Config::set($key, $swaps[$current]);
                $changed[$key] = $swaps[$current];
            }
        }

        return $changed;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function configuredMap(): array
    {
        $configured = Config::get('laranail.db-tools.boot_without_database.drivers');

        return is_array($configured) && $configured !== []
            ? $configured
            : self::DEFAULT_MAP;
    }
}
