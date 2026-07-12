<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Pagination;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Pagination\Pagination;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class PaginationWidget extends Model
{
    protected $table = 'pagination_widgets';

    protected $guarded = [];

    public $timestamps = false;
}

final class PaginationTest extends TestCase
{
    public function test_paginate_slices_an_array(): void
    {
        $items = range(1, 10);

        $page = Pagination::paginate($items, perPage: 3, currentPage: 2);

        self::assertInstanceOf(LengthAwarePaginator::class, $page);
        self::assertSame([4, 5, 6], array_values($page->items()));
        self::assertSame(10, $page->total());
        self::assertSame(4, $page->lastPage());
    }

    public function test_paginate_clamps_non_positive_inputs(): void
    {
        $page = Pagination::paginate(range(1, 5), perPage: 0, currentPage: 0);

        self::assertSame([1], array_values($page->items()));
    }

    public function test_paginate_query_paginates_a_builder(): void
    {
        Schema::create('pagination_widgets', function ($t): void {
            $t->id();
            $t->string('name');
        });

        foreach (range(1, 7) as $i) {
            PaginationWidget::create(['name' => "row {$i}"]);
        }

        $page = Pagination::paginateQuery(PaginationWidget::query()->orderBy('id'), perPage: 5, page: 1);

        self::assertSame(7, $page->total());
        self::assertCount(5, $page->items());
    }
}
