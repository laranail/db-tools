<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Concerns\HasThreadedParentChildrenRecords;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class CommentNode extends Model
{
    use HasThreadedParentChildrenRecords;

    protected $table = 'comment_nodes';

    protected $guarded = [];

    protected string $threadScopeColumn = 'post_id';
}

final class HasThreadedParentChildrenRecordsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('comment_nodes', function ($t): void {
            $t->id();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedBigInteger('post_id')->nullable();
            $t->string('body')->nullable();
            $t->timestamps();
        });
    }

    public function test_parent_and_children_relations(): void
    {
        $root = CommentNode::create(['body' => 'root', 'post_id' => 1]);
        $child = CommentNode::create(['body' => 'child', 'post_id' => 1, 'parent_id' => $root->id]);

        self::assertTrue($root->isParent());
        self::assertFalse($child->isParent());
        self::assertTrue($root->hasChildren());
        self::assertSame($root->id, $child->parent->id);
        self::assertSame($child->id, $root->children->first()->id);
    }

    public function test_threaded_tree_is_scoped(): void
    {
        $rootA = CommentNode::create(['body' => 'a-root', 'post_id' => 1]);
        CommentNode::create(['body' => 'a-child', 'post_id' => 1, 'parent_id' => $rootA->id]);
        CommentNode::create(['body' => 'b-root', 'post_id' => 2]);

        $tree = (new CommentNode)->getAsThreadedParentToChildren(1);

        self::assertCount(1, $tree);
        self::assertSame('a-root', $tree->first()->body);
        self::assertCount(1, $tree->first()->descendants);
    }
}
