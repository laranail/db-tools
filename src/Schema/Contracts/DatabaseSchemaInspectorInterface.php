<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema\Contracts;

/**
 * Interface DatabaseSchemaInspectorInterface
 *
 * Defines contract for inspecting database schema.
 */
interface DatabaseSchemaInspectorInterface
{
    /**
     * Get all table names in the database
     *
     * @param string|null $connection Connection name (null for default)
     *
     * @return array List of table names
     */
    public function getTables(?string $connection = null): array;

    /**
     * Check if a specific table exists
     *
     * @param string $table Table name
     * @param string|null $connection Connection name (null for default)
     *
     * @return bool True if table exists
     */
    public function hasTable(string $table, ?string $connection = null): bool;

    /**
     * Get total count of tables in the database
     *
     * @param string|null $connection Connection name (null for default)
     *
     * @return int Number of tables
     */
    public function getTableCount(?string $connection = null): int;

    /**
     * Get all column names for a specific table
     *
     * @param string $table Table name
     * @param string|null $connection Connection name (null for default)
     *
     * @return array List of column names
     */
    public function getColumns(string $table, ?string $connection = null): array;

    /**
     * Check if a table has a specific column
     *
     * @param string $table Table name
     * @param string $column Column name
     * @param string|null $connection Connection name (null for default)
     *
     * @return bool True if column exists
     */
    public function hasColumn(string $table, string $column, ?string $connection = null): bool;

    /**
     * Check if a table has multiple columns
     *
     * @param string $table Table name
     * @param array $columns Column names
     * @param string|null $connection Connection name (null for default)
     *
     * @return bool True if all columns exist
     */
    public function hasColumns(string $table, array $columns, ?string $connection = null): bool;
}
