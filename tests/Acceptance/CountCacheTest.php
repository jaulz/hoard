<?php
namespace Tests\Acceptance;

use Illuminate\Support\Facades\DB;
use Tests\Acceptance\Models\Comment;
use Tests\Acceptance\Models\Post;
use Tests\Acceptance\Models\User;

class CountCacheTest extends AcceptanceTestCase
{
    private $data = [];

    public function init()
    {
        $this->data = $this->setupUserAndPost();
    }

    public function testUserCountCache()
    {
        $user = User::first();

        $this->assertEquals(1, $user->postCount);
        $this->assertEquals(1, $user->postCountExplicit);
    }

    public function testComplexCountCache()
    {
        $post = new Post;
        $post->userId = $this->data['user']->id;
        $post->visible = true;
        $post->weight = 3;
        $post->save();

        $this->assertEquals(2, User::first()->postCount);
        $this->assertEquals(2, User::first()->postCountExplicit);
        $this->assertEquals(1, User::first()->postCountConditional);
        $this->assertEquals(0, User::first()->postCountComplexConditional);
        $this->assertEquals(0, User::first()->commentCount);
        $this->assertEquals(null, Post::first()->firstCommentedAt);

        $comment = new Comment;
        $comment->userId = $this->data['user']->id;
        $comment->postId = $this->data['post']->id;
        $comment->save();

        $this->assertEquals(1, User::first()->commentCount);
        $this->assertEquals(1, User::first()->postCommentSum);
        $this->assertEquals(1, Post::first()->commentCount);
        $this->assertEquals($comment->createdAt, Post::first()->firstCommentedAt);
        $this->assertEquals($comment->createdAt, Post::first()->lastCommentedAt);

        $comment->postId = $post->id;
        $comment->save();

        $this->assertEquals(0, Post::first()->commentCount);
        $this->assertEquals(1, Post::get()[1]->commentCount);
        $this->assertEquals(0, User::first()->postCountComplexConditional);

        $post->weight = 8;
        $post->save();

        $this->assertEquals(0, Post::first()->commentCount);
        $this->assertEquals(1, Post::get()[1]->commentCount);
        $this->assertEquals(1, User::first()->postCountComplexConditional);

        $post->delete();

        $this->assertEquals(1, User::first()->postCount);
        $this->assertEquals(1, User::first()->postCountExplicit);
        $this->assertEquals(0, User::first()->postCountConditional);
        $this->assertEquals(0, User::first()->postCountComplexConditional);

        $comment->delete();

        $this->assertEquals(0, User::first()->postCommentSum);
    }

    public function testItCanHandleNegativeCounts()
    {
        $post = new Post;
        $post->userId = $this->data['user']->id;
        $post->visible = false;
        $post->save();

        $comment = new Comment;
        $comment->userId = $this->data['user']->id;
        $comment->postId = $this->data['post']->id;
        $comment->save();

        $this->assertEquals(1, Post::first()->commentCount);

        $comment->delete();

        $this->assertEquals(0, Post::first()->commentCount);
        $this->assertEquals(null, Post::first()->firstCommentedAt);
        $this->assertEquals(null, Post::first()->lastCommentedAt);

        $secondComment = new Comment;
        $secondComment->userId = $this->data['user']->id;
        $secondComment->postId = $this->data['post']->id;
        $secondComment->save();

        $this->assertEquals(1, Post::first()->commentCount);
        $this->assertEquals($secondComment->createdAt, Post::first()->firstCommentedAt);
        $this->assertEquals($secondComment->createdAt, Post::first()->lastCommentedAt);
        
        $comment->restore();

        $this->assertEquals(2, Post::first()->commentCount);
        $this->assertEquals($comment->createdAt, Post::first()->firstCommentedAt);
        $this->assertEquals($secondComment->createdAt, Post::first()->lastCommentedAt);
    }

    private function setupUserAndPost()
    {
        $user = new User;
        $user->firstName = 'Kirk';
        $user->lastName = 'Bushell';
        $user->save();

        $post = new Post;
        $post->userId = $user->id;
        $post->visible = false;
        $post->save();

        return compact('user', 'post');
    }
}
