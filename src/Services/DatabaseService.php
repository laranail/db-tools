<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Services;

use Exception;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Simtabi\Laranail\DbTools\Services\Contracts\DatabaseServiceInterface;

/**
 * Database Service Implementation
 *
 * Database helpers and query utilities. Filesystem housekeeping
 * (cache/log/symlink) lives in {@see MaintenanceService}.
 */
final readonly class DatabaseService implements DatabaseServiceInterface
{
    public function __construct(
        private LoggerInterface $logger
    ) {}

    /**
     * {@inheritDoc}
     */
    public function isJoined(mixed $query, string $table): bool
    {
        if ($query instanceof EloquentBuilder) {
            $query = $query->getQuery();
        }

        if (! $query instanceof QueryBuilder) {
            return false;
        }

        $joins = $query->joins;

        if ($joins === null) {
            return false;
        }

        foreach ($joins as $join) {
            if ($join->table === $table) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function modifyTimestamps(array $dates, Model $model): bool
    {
        if ($dates === []) {
            return false;
        }

        try {
            $model->timestamps = false;

            foreach ($dates as $column => $date) {
                $model->$column = $date;
            }

            $result = $model->save();

            if ($result) {
                $this->logger->info('Model timestamps modified', [
                    'model' => $model::class,
                    'id' => $model->getKey(),
                    'columns' => array_keys($dates),
                ]);
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Failed to modify timestamps', [
                'model' => $model::class,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function handleViewCount(Model $object, string $sessionName): bool
    {

        $sessionKey = $sessionName.'.'.$object->getKey();

        if (session()->has($sessionKey)) {
            return false;
        }

        try {
            // On the instance, not newQuery(): the latter is unconstrained, so
            // it incremented `views` on every row in the table.
            $object->increment('views');
            session()->put($sessionKey, time());

            $this->logger->debug('View count incremented', [
                'model' => $object::class,
                'id' => $object->getKey(),
            ]);

            return true;
        } catch (Exception $exception) {
            $this->logger->error('Failed to increment view count', [
                'model' => $object::class,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function setMorphClassNames(array $aliases): void
    {
        $oldAliases = config('app.aliases', []);
        config(['app.aliases' => array_merge($oldAliases, $aliases)]);

        $this->logger->info('Morph class aliases set', [
            'count' => count($aliases),
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function generateRelationshipSyncData(
        string|array $ids,
        array $data = [],
        string $columnName = 'id'
    ): array {
        $ids = is_array($ids) ? $ids : [$ids];
        $out = [];

        foreach ($ids as $id) {
            if (! empty($id)) {
                $out[trim((string) $id)] = array_filter(array_merge([
                    $columnName => Str::uuid()->toString(),
                ], $data));
            }
        }

        return $out;
    }
}
