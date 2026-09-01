<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Pagination;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use JsonSerializable;

/**
 * Lightweight DTO over Laravel's native {@see CursorPaginator}.
 *
 * Wraps the result of a native `cursorPaginate()` call into a stable,
 * serialization-friendly shape with explicit `data` + `meta` keys. It does not
 * replace cursor pagination — it presents it.
 *
 * Usage:
 * ```
 * $page = CursorPage::fromPaginator(
 *     Model::query()->orderBy('id')->cursorPaginate()
 * );
 *
 * return $page; // Responsable: JSON { data: [...], meta: {...} }
 * ```
 *
 * @template TItem
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class CursorPage implements Arrayable, JsonSerializable, Responsable
{
    /**
     * @param  array<int, TItem>  $data
     */
    public function __construct(
        public array $data,
        public int $perPage,
        public ?string $nextCursor,
        public ?string $prevCursor,
        public bool $hasMore,
    ) {}

    /**
     * Build the DTO from a native cursor paginator.
     *
     * @template TModelKey of array-key
     * @template TModel
     *
     * @param  CursorPaginator<TModelKey, TModel>  $paginator
     * @return self<TModel>
     */
    public static function fromPaginator(CursorPaginator $paginator): self
    {
        return new self(
            data: $paginator->items(),
            perPage: $paginator->perPage(),
            nextCursor: $paginator->nextCursor()?->encode(),
            prevCursor: $paginator->previousCursor()?->encode(),
            hasMore: $paginator->hasMorePages(),
        );
    }

    /**
     * @return array{data: array<int, TItem>, meta: array{per_page: int, next_cursor: string|null, prev_cursor: string|null, has_more: bool}}
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'meta' => [
                'per_page' => $this->perPage,
                'next_cursor' => $this->nextCursor,
                'prev_cursor' => $this->prevCursor,
                'has_more' => $this->hasMore,
            ],
        ];
    }

    /**
     * @return array{data: array<int, TItem>, meta: array{per_page: int, next_cursor: string|null, prev_cursor: string|null, has_more: bool}}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        return response()->json($this->toArray());
    }
}
