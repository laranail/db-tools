<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Schema\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Override;

/**
 * Global scope that hides archived records and registers the archive/restore
 * builder macros, mirroring Laravel's SoftDeletingScope but keyed off an
 * `archived_at` column instead of `deleted_at`.
 *
 * Paired with the {@see HasArchiver} trait, which supplies the
 * `getArchivedAtColumn()` / `getQualifiedArchivedAtColumn()` methods the macros
 * below call on the model.
 *
 * @implements Scope<Model>
 */
final class ArchiveScope implements Scope
{
    /**
     * Builder macros registered by {@see self::extend()}.
     *
     * @var list<string>
     */
    private array $extensions = ['Archive', 'UnArchive', 'WithArchived', 'WithoutArchived', 'OnlyArchived'];

    /**
     * @param Builder<*> $builder
     */
    #[Override]
    public function apply(Builder $builder, Model $model): void
    {
        if (method_exists($model, 'usesArchiving') && ! $model->usesArchiving()) {
            return;
        }

        if (method_exists($model, 'getQualifiedArchivedAtColumn')) {
            $builder->whereNull($model->getQualifiedArchivedAtColumn());
        }
    }

    /**
     * @param Builder<*> $builder
     */
    public function extend(Builder $builder): void
    {
        foreach ($this->extensions as $extension) {
            $this->{'add'.$extension}($builder);
        }
    }

    /**
     * @param Builder<*> $builder
     */
    private function getArchivedAtColumn(Builder $builder): string
    {
        $model = $builder->getModel();

        if (count((array) $builder->getQuery()->joins) > 0) {
            return $model->getQualifiedArchivedAtColumn();
        }

        return $model->getArchivedAtColumn();
    }

    /**
     * @param Builder<*> $builder
     */
    private function addArchive(Builder $builder): void
    {
        $builder->macro('archive', function (Builder $builder) {
            $column = $this->getArchivedAtColumn($builder);

            return $builder->update([
                $column => $builder->getModel()->freshTimestampString(),
            ]);
        });
    }

    /**
     * @param Builder<*> $builder
     */
    private function addUnArchive(Builder $builder): void
    {
        $builder->macro('unArchive', function (Builder $builder) {
            $builder->withArchived();

            $column = $this->getArchivedAtColumn($builder);

            return $builder->update([$column => null]);
        });
    }

    /**
     * @param Builder<*> $builder
     */
    private function addWithArchived(Builder $builder): void
    {
        $builder->macro('withArchived', function (Builder $builder, bool $withArchived = true) {
            if (! $withArchived) {
                return $builder->withoutArchived();
            }

            return $builder->withoutGlobalScope(self::class);
        });
    }

    /**
     * @param Builder<*> $builder
     */
    private function addWithoutArchived(Builder $builder): void
    {
        $builder->macro('withoutArchived', function (Builder $builder) {
            $model = $builder->getModel();

            return $builder->withoutGlobalScope(self::class)->whereNull(
                $model->getQualifiedArchivedAtColumn()
            );
        });
    }

    /**
     * @param Builder<*> $builder
     */
    private function addOnlyArchived(Builder $builder): void
    {
        $builder->macro('onlyArchived', function (Builder $builder) {
            $model = $builder->getModel();

            return $builder->withoutGlobalScope(self::class)->whereNotNull(
                $model->getQualifiedArchivedAtColumn()
            );
        });
    }
}
