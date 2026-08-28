<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Pagination;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Pagination\CursorPage;

final class CursorPageFixture extends Model
{
    protected $table = 'cursor_page_fixtures';

    protected $guarded = [];
}

final class CursorPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cursor_page_fixtures', function ($t): void {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });

        foreach (range(1, 5) as $i) {
            CursorPageFixture::create(['name' => "row {$i}"]);
        }
    }

    public function test_to_array_shape(): void
    {
        $page = $this->firstPage(2);
        $array = $page->toArray();

        self::assertArrayHasKey('data', $array);
        self::assertArrayHasKey('meta', $array);
        self::assertCount(2, $array['data']);

        $meta = $array['meta'];
        self::assertSame(2, $meta['per_page']);
        self::assertArrayHasKey('next_cursor', $meta);
        self::assertArrayHasKey('prev_cursor', $meta);
        self::assertTrue($meta['has_more']);
        self::assertIsString($meta['next_cursor']);
        self::assertNull($meta['prev_cursor']);
    }

    public function test_last_page_has_no_more(): void
    {
        $paginator = CursorPageFixture::query()->orderBy('id')->cursorPaginate(10);
        $page = CursorPage::fromPaginator($paginator);

        self::assertFalse($page->hasMore);
        self::assertNull($page->nextCursor);
        self::assertCount(5, $page->data);
    }

    public function test_json_serialize_equals_to_array(): void
    {
        $page = $this->firstPage(2);

        self::assertSame($page->toArray(), $page->jsonSerialize());
    }

    public function test_to_response_returns_json_response_with_payload(): void
    {
        $page = $this->firstPage(2);

        $response = $page->toResponse(Request::create('/'));

        self::assertInstanceOf(JsonResponse::class, $response);

        $decoded = $response->getData(true);

        // The DTO's meta block round-trips identically through JSON.
        self::assertSame($page->toArray()['meta'], $decoded['meta']);
        // The data block carries the paginated models, serialized.
        self::assertCount(2, $decoded['data']);
        self::assertSame('row 1', $decoded['data'][0]['name']);
    }

    private function firstPage(int $perPage = 2): CursorPage
    {
        $paginator = CursorPageFixture::query()->orderBy('id')->cursorPaginate($perPage);

        return CursorPage::fromPaginator($paginator);
    }
}
