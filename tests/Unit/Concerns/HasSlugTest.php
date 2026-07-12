<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Concerns\HasSlug;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class PageModel extends Model
{
    use HasSlug;

    protected $table = 'pages';

    protected $guarded = [];

    public $timestamps = false;
}

final class HasSlugTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pages', function ($t): void {
            $t->id();
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
        });
    }

    public function test_slug_is_generated_from_the_source_column(): void
    {
        $page = PageModel::create(['name' => 'Hello World']);

        self::assertSame('hello-world', $page->slug);
    }

    public function test_slug_exists_and_by_slug_scope(): void
    {
        PageModel::create(['name' => 'Hello World']);

        self::assertTrue(PageModel::slugExists('hello-world'));
        self::assertFalse(PageModel::slugExists('nope'));
        self::assertSame('Hello World', PageModel::query()->bySlug('hello-world')->first()->name);
    }

    public function test_check_model_slug_disambiguates_existing_slugs(): void
    {
        PageModel::create(['name' => 'Hello World']);

        self::assertNotSame('hello-world', PageModel::checkModelSlug('hello-world'));
        self::assertSame('unique-slug', PageModel::checkModelSlug('unique-slug'));
    }
}
