<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema;

use Exception;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseTableVerifierInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseSchemaInspectorInterface;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseConnectionTesterInterface;

/**
 * Class DatabaseTableVerifier
 *
 * Verifies database state by checking table existence and connection status.
 * Combines connection testing with schema inspection for comprehensive verification.
 */
class DatabaseTableVerifier implements DatabaseTableVerifierInterface
{
    public function __construct(
        private readonly DatabaseConnectionTesterInterface $connectionTester,
        private readonly DatabaseSchemaInspectorInterface $inspector,
    ) {}

    /**
     * Verify tables exist in the database
     *
     * @param array $tables List of table names to check
     * @param bool $requireAll If true, all tables must exist
     * @param string|null $connection Connection name (null for default)
     *
     * @return bool True if verification passes
     */
    public function verify(array $tables, bool $requireAll = false, ?string $connection = null): bool
    {
        if ($tables === []) {
            return true;
        }

        $existing = $this->getExistingTables($tables, $connection);

        return $requireAll
            ? count($existing) === count($tables)
            : $existing !== [];
    }

    /**
     * Verify tables exist and return detailed information
     *
     * @param array $tables List of table names to check
     * @param bool $requireAll If true, all tables must exist
     * @param bool $testConnection Whether to test connection first
     * @param string|null $connection Connection name (null for default)
     *
     * @return array Detailed verification results
     */
    public function verifyDetailed(
        array $tables,
        bool $requireAll = false,
        bool $testConnection = true,
        ?string $connection = null,
    ): array {
        try {
            if ($testConnection) {
                $connectionTest = $this->connectionTester->testDetailed($connection);
                if (! $connectionTest['success']) {
                    return [
                        'success'   => false,
                        'connected' => false,
                        'message'   => $connectionTest['message'],
                        'driver'    => $connectionTest['driver'] ?? null,
                    ];
                }
            }

            if ($tables === []) {
                return [
                    'success'   => true,
                    'connected' => true,
                    'message'   => 'No tables to verify',
                    'tables'    => [],
                ];
            }

            $existing = $this->getExistingTables($tables, $connection);
            $missing = array_values(array_diff($tables, $existing));

            $allExist = count($existing) === count($tables);
            $anyExist = $existing !== [];
            $success = $requireAll ? $allExist : $anyExist;

            $result = [
                'success'   => $success,
                'connected' => true,
                'tables'    => [
                    'checked'  => array_values($tables),
                    'existing' => array_values($existing),
                    'missing'  => $missing,
                    'stats'    => [
                        'total'      => count($tables),
                        'found'      => count($existing),
                        'missing'    => count($missing),
                        'percentage' => count($tables) > 0
                            ? round((count($existing) / count($tables)) * 100, 2)
                            : 100,
                    ],
                ],
                'message' => $success
                    ? 'All checks passed'
                    : ($requireAll
                        ? sprintf('Missing %d of %d required tables', count($missing), count($tables))
                        : 'No tables found'),
                'requirement' => $requireAll ? 'all' : 'any',
            ];

            if ($testConnection) {
                $result['connection'] = [
                    'name'    => ConnectionContext::for($connection)->key(),
                    'driver'  => $connectionTest['driver'] ?? null,
                    'version' => $connectionTest['version'] ?? null,
                ];
            }

            return $result;
        } catch (Exception $e) {
            return [
                'success'    => false,
                'connected'  => false,
                'message'    => 'Verification error: ' . $e->getMessage(),
                'error_type' => $e::class,
            ];
        }
    }

    /**
     * Get list of tables that exist from the provided list
     *
     * @param array $tables Tables to check
     * @param string|null $connection Connection name (null for default)
     *
     * @return array List of existing tables
     */
    public function getExistingTables(array $tables, ?string $connection = null): array
    {
        $existing = [];

        foreach ($tables as $table) {
            if ($this->inspector->hasTable($table, $connection)) {
                $existing[] = $table;
            }
        }

        return $existing;
    }

    /**
     * Get list of tables that don't exist from the provided list
     *
     * @param array $tables Tables to check
     * @param string|null $connection Connection name (null for default)
     *
     * @return array List of missing tables
     */
    public function getMissingTables(array $tables, ?string $connection = null): array
    {
        $existing = $this->getExistingTables($tables, $connection);

        return array_values(array_diff($tables, $existing));
    }

    /**
     * Quick check if essential Laravel tables exist
     *
     * @param bool $strict Whether all default tables must exist
     * @param string|null $connection Connection name (null for default)
     *
     * @return bool True if tables exist according to strict setting
     */
    public function hasLaravelTables(bool $strict = false, ?string $connection = null): bool
    {
        $defaultTables = ['migrations', 'users', 'password_reset_tokens', 'failed_jobs'];

        return $this->verify($defaultTables, $strict, $connection);
    }
}
