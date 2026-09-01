<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Services\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Database Service Interface
 *
 * Provides database helpers and query utilities.
 */
interface DatabaseServiceInterface
{
    /**
     * Check if a database table join already exists
     *
     * @param  mixed  $query  The query builder instance
     * @param  string  $table  The table name to check for
     * @return bool True if join exists, false otherwise
     */
    public function isJoined(mixed $query, string $table): bool;

    /**
     * Modify timestamps on an Eloquent model
     *
     * @param  array  $dates  Array of date values to set
     * @param  Model  $model  The model instance to update
     * @return bool True on success, false on failure
     */
    public function modifyTimestamps(array $dates, Model $model): bool;

    /**
     * Handle view count increment with session tracking
     *
     * @param  Model  $object  The model to increment views on
     * @param  string  $sessionName  The session key for tracking
     * @return bool True if view was counted, false if already viewed
     */
    public function handleViewCount(Model $object, string $sessionName): bool;

    /**
     * Register polymorphic type aliases in Eloquent's morph map.
     *
     * Merges into the existing map rather than replacing it, so several
     * callers can each contribute their own types.
     *
     * @param  array<string, class-string<Model>>  $aliases  alias => model class
     */
    public function setMorphClassNames(array $aliases): void;

    /**
     * Generate relationship sync data
     *
     * @param  string|array  $ids  The IDs to sync
     * @param  array  $data  Additional pivot data
     * @param  string  $columnName  The column name for IDs (default: 'id')
     * @return array Formatted sync data
     */
    public function generateRelationshipSyncData(
        string|array $ids,
        array $data = [],
        string $columnName = 'id',
    ): array;
}
