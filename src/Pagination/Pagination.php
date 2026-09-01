<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Pagination;

use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Offset (page-number) pagination helpers, for cases where a numbered pager is
 * required. For high-throughput, keyset pagination prefer {@see CursorPage}.
 */
final class Pagination
{
    /**
     * Paginate an in-memory array slice into a length-aware paginator.
     *
     * @param  array<array-key, mixed>  $items
     * @param  array<string, mixed>  $options
     * @return LengthAwarePaginator<int, mixed>
     */
    public static function paginate(array $items, int $perPage, int $currentPage, array $options = []): LengthAwarePaginator
    {
        // Guard against zero/negative inputs (a negative offset would slice from
        // the end of the array and return the wrong page).
        $perPage = max(1, $perPage);
        $currentPage = max(1, $currentPage);

        return new LengthAwarePaginator(
            array_slice($items, ($currentPage - 1) * $perPage, $perPage),
            count($items),
            $perPage,
            $currentPage,
            $options,
        );
    }

    /**
     * Paginate an Eloquent or query builder, appending the given query options.
     *
     * @param  Builder|EloquentBuilder<Model>  $query
     * @param  array<string, mixed>  $options
     * @return LengthAwarePaginatorContract<int, mixed>
     */
    public static function paginateQuery($query, int $perPage, ?int $page = null, array $options = []): LengthAwarePaginatorContract
    {
        $page ??= request()->integer('page', 1);

        // Same guard as paginate() above. Both values routinely originate in
        // request input, and unclamped a perPage of 0 reaches LengthAwarePaginator's
        // ceil($total / $perPage) as a raw DivisionByZeroError, while a page of 0
        // yields a negative SQL offset.
        $perPage = max(1, $perPage);
        $page = max(1, $page);

        return $query->paginate($perPage, ['*'], 'page', $page)->appends($options);
    }
}
