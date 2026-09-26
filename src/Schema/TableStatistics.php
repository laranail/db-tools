<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

use Throwable;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;

/**
 * Per-table storage size and planner statistics, on every supported driver.
 *
 * The obvious query — `pg_total_relation_size()` — exists only on PostgreSQL,
 * so code that hard-wires it reports nothing on MySQL, MariaDB or SQLite and
 * usually swallows the error into "N/A". Each driver answers here in its own
 * dialect:
 *
 * | Driver          | Size                                            | Analyze          |
 * |-----------------|-------------------------------------------------|------------------|
 * | pgsql           | `pg_total_relation_size(to_regclass(?))`        | `ANALYZE t`      |
 * | mysql / mariadb | `information_schema.TABLES` data + index length | `ANALYZE TABLE t`|
 * | sqlite          | `dbstat` virtual table, when compiled in        | `ANALYZE t`      |
 *
 * A size is `null` when the table does not exist or the driver cannot say
 * (SQLite without `dbstat`). Nothing here throws for a missing table.
 */
final class TableStatistics
{
    /**
     * @param list<string> $tables Unprefixed table names
     *
     * @return array<string, int|null> Bytes per table, keyed by the name given
     */
    public static function sizes(array $tables, ?string $connection = null): array
    {
        $db = ConnectionContext::for($connection)->connection();
        $prefix = $db->getTablePrefix();
        $sizes = [];

        foreach ($tables as $table) {
            $name = $prefix . $table;

            try {
                $value = match ($db->getDriverName()) {
                    'pgsql'            => $db->selectOne('SELECT pg_total_relation_size(to_regclass(?)) AS size', [$name])?->size,
                    'mysql', 'mariadb' => $db->selectOne(
                        'SELECT data_length + index_length AS size FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = ?',
                        [$name],
                    )?->size,
                    'sqlite' => $db->selectOne('SELECT SUM(pgsize) AS size FROM dbstat WHERE name = ?', [$name])?->size,
                    default  => null,
                };
            } catch (Throwable) {
                $value = null;
            }

            $sizes[$table] = is_numeric($value) ? (int) $value : null;
        }

        return $sizes;
    }

    /**
     * Refresh the query planner's statistics for the given tables — worth doing
     * after a bulk load, so the first queries against it are not planned blind.
     *
     * @param list<string> $tables Unprefixed table names
     */
    public static function analyze(array $tables, ?string $connection = null): void
    {
        $db = ConnectionContext::for($connection)->connection();
        $grammar = $db->getQueryGrammar();

        foreach ($tables as $table) {
            $wrapped = $grammar->wrapTable($table);

            match ($db->getDriverName()) {
                'mysql', 'mariadb' => $db->statement("ANALYZE TABLE {$wrapped}"),
                'pgsql', 'sqlite'  => $db->statement("ANALYZE {$wrapped}"),
                default            => null,
            };
        }
    }
}
