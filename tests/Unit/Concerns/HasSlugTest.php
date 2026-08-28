<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Concerns\HasSlug;

final class PageModel extends Model
{
    use HasSlug;

    public $timestamps = false;

    protected $table = 'pages';

    protected $guarded = [];
}

/**
 * A model whose slug lives somewhere other than a column literally named
 * "slug" — the configurable case getSlugDestColumnName() exists to support.
 */
final class PermalinkPageModel extends Model
{
    use HasSlug;

    public $timestamps = false;

    protected $table = 'permalink_pages';

    protected $guarded = [];

    protected string $slugDestColumnName = 'permalink';
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

        Schema::create('permalink_pages', function ($t): void {
            $t->id();
            $t->string('name')->nullable();
            $t->string('permalink')->nullable();
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

    public function test_slug_exists_honours_a_configured_destination_column(): void
    {
        // slugExists() and bySlug() hardcoded 'slug' as the default column,
        // ignoring getSlugDestColumnName(). On a model that stores its slug
        // elsewhere the query hit a column that does not exist — or, on a table
        // that happens to also have a "slug" column, quietly answered about the
        // wrong one.
        PermalinkPageModel::create(['name' => 'Hello World']);

        self::assertSame('hello-world', PermalinkPageModel::query()->first()->permalink);
        self::assertTrue(PermalinkPageModel::slugExists('hello-world'));
        self::assertFalse(PermalinkPageModel::slugExists('nope'));
    }

    public function test_by_slug_scope_honours_a_configured_destination_column(): void
    {
        PermalinkPageModel::create(['name' => 'Hello World']);

        self::assertSame(
            'Hello World',
            PermalinkPageModel::query()->bySlug('hello-world')->first()?->name,
        );
    }

    public function test_check_model_slug_honours_a_configured_destination_column(): void
    {
        PermalinkPageModel::create(['name' => 'Hello World']);

        self::assertNotSame('hello-world', PermalinkPageModel::checkModelSlug('hello-world'));
        self::assertSame('free-slug', PermalinkPageModel::checkModelSlug('free-slug'));
    }
}
