# Cursor pagination

`Simtabi\Laranail\DbTools\Pagination\CursorPage` is a small DTO over
Laravel's native cursor paginator. It does not replace cursor pagination — it
presents it in a stable, serialization-friendly shape with explicit `data` +
`meta` keys.

## Usage

Build it from a native `cursorPaginate()` result. Cursor pagination needs a
deterministic order, so always order by a unique, sequential column:

```php
use Simtabi\Laranail\DbTools\Pagination\CursorPage;

$page = CursorPage::fromPaginator(
    Order::query()->orderBy('id')->cursorPaginate()
);

return $page; // Responsable: JSON { "data": [...], "meta": {...} }
```

`CursorPage` implements `Responsable`, `Arrayable`, and `JsonSerializable`, so
you can return it directly from a controller, call `->toArray()`, or pass it
anywhere Laravel JSON-encodes a value.

## Shape

```json
{
  "data": [ /* the page items */ ],
  "meta": {
    "per_page": 15,
    "next_cursor": "eyJpZCI6MTV9",
    "prev_cursor": null,
    "has_more": true
  }
}
```

The cursors are the encoded strings from the native paginator's
`nextCursor()` / `previousCursor()`; pass them back as the `cursor` query
parameter (Laravel reads it automatically) to fetch adjacent pages.

## Reference

```php
public function __construct(
    public array $data,
    public int $perPage,
    public ?string $nextCursor,
    public ?string $prevCursor,
    public bool $hasMore,
);

public static function fromPaginator(CursorPaginator $paginator): self;
public function toArray(): array;        // { data, meta }
public function jsonSerialize(): array;  // same as toArray()
public function toResponse($request): JsonResponse;
```

`CursorPage` is a `final readonly class`. For raw cursor pagination without the
DTO wrapper, use Laravel's native
[`cursorPaginate()`](https://laravel.com/docs/pagination#cursor-pagination)
directly.

## Offset pagination (`Pagination`)

When a numbered pager (total/last-page) is required rather than a keyset cursor,
`Pagination` wraps Laravel's `LengthAwarePaginator` for both in-memory arrays and
query builders:

```php
use Simtabi\Laranail\DbTools\Pagination\Pagination;

// Array slice → length-aware paginator (non-positive inputs are clamped to 1):
$page = Pagination::paginate($items, perPage: 15, currentPage: 2);

// Eloquent/query builder, appending extra query-string params:
$page = Pagination::paginateQuery(Post::query(), perPage: 25, options: ['sort' => 'new']);
```

Prefer `CursorPage` for high-throughput / deep pagination; reach for offset
pagination when you need page numbers and totals.

---
[← Docs index](../../README.md#documentation)
