<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use InvalidArgumentException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Concerns\HasScopes;

final class ArticleModel extends Model
{
    use HasScopes;

    public $timestamps = false;

    protected $table = 'articles';

    protected $guarded = [];

    /** @var array<int, string> */
    protected array $searchable = ['title', 'body'];
}

final class HasScopesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('articles', function ($t): void {
            $t->id();
            $t->string('title')->nullable();
            $t->string('body')->nullable();
        });

        ArticleModel::insert([
            ['title' => 'Laravel tips', 'body' => 'about queues'],
            ['title' => 'Cooking', 'body' => 'about pasta'],
        ]);
    }

    public function test_search_falls_back_to_like_on_sqlite(): void
    {
        $results = ArticleModel::query()->search('Laravel')->get();

        self::assertCount(1, $results);
        self::assertSame('Laravel tips', $results->first()->title);
    }

    public function test_search_uses_explicit_columns(): void
    {
        $results = ArticleModel::query()->search('pasta', ['body'])->get();

        self::assertCount(1, $results);
        self::assertSame('Cooking', $results->first()->title);
    }

    public function test_blank_term_returns_all(): void
    {
        self::assertCount(2, ArticleModel::query()->search('   ')->get());
    }

    public function test_search_rejects_columns_the_model_does_not_declare(): void
    {
        // $searchable is a public scope parameter, so `search($q, request('cols'))`
        // is an inviting shape. The term is bound; the columns were interpolated
        // straight into the SQL. Only declared columns may be searched.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not searchable');

        ArticleModel::query()->search('x', ['title), 1) UNION SELECT 1 --'])->get();
    }

    public function test_search_treats_like_wildcards_in_the_term_as_literals(): void
    {
        // Unescaped, '%' matched every row.
        self::assertCount(0, ArticleModel::query()->search('%')->get());
        self::assertCount(0, ArticleModel::query()->search('_aravel')->get());
    }
}
