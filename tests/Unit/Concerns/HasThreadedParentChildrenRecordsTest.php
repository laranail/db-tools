<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Concerns\HasThreadedParentChildrenRecords;

final class CommentNode extends Model
{
    use HasThreadedParentChildrenRecords;

    protected $table = 'comment_nodes';

    protected $guarded = [];

    protected string $threadScopeColumn = 'post_id';
}

/**
 * Threads across the whole table (threadScopeColumn() returns null).
 */
final class UnscopedNode extends Model
{
    use HasThreadedParentChildrenRecords;

    protected $table = 'comment_nodes';

    protected $guarded = [];
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

    public function test_children_do_not_cross_the_thread_scope(): void
    {
        // The scope column is applied to the ROOT query in
        // getAsThreadedParentToChildren(), but children() and descendants()
        // matched on parent_id alone. A row whose parent_id points at a record
        // in another thread — reparented, imported, or simply written with a
        // stale id — was pulled into that thread's tree. With post_id standing
        // in for a tenant, that is a cross-tenant read.
        $root = CommentNode::create(['body' => 'root', 'post_id' => 1]);

        CommentNode::create(['body' => 'ours', 'post_id' => 1, 'parent_id' => $root->id]);
        CommentNode::create(['body' => 'theirs', 'post_id' => 2, 'parent_id' => $root->id]);

        $bodies = $root->children()->pluck('body')->all();

        self::assertSame(['ours'], $bodies);
    }

    public function test_threaded_tree_does_not_leak_across_the_scope(): void
    {
        $root = CommentNode::create(['body' => 'root', 'post_id' => 1]);
        CommentNode::create(['body' => 'ours', 'post_id' => 1, 'parent_id' => $root->id]);
        CommentNode::create(['body' => 'theirs', 'post_id' => 2, 'parent_id' => $root->id]);

        $tree = (new CommentNode)->getAsThreadedParentToChildren(1);

        self::assertCount(1, $tree);
        self::assertSame(['ours'], $tree->first()->descendants->pluck('body')->all());
    }

    public function test_a_model_without_a_thread_scope_is_unaffected(): void
    {
        $root = UnscopedNode::create(['body' => 'root', 'post_id' => 1]);
        UnscopedNode::create(['body' => 'a', 'post_id' => 1, 'parent_id' => $root->id]);
        UnscopedNode::create(['body' => 'b', 'post_id' => 2, 'parent_id' => $root->id]);

        self::assertCount(2, $root->children()->get());
    }
}
