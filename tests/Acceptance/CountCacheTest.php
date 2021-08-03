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

        $this->assertEquals(1, $user->posts_count);
        $this->assertEquals(1, $user->posts_count_explicit);
    }

    public function testComplexCountCache()
    {
        $post = new Post;
        $post->user_id = $this->data['user']->id;
        $post->visible = true;
        $post->weight = 3;
        $post->save();

        $this->assertEquals(2, User::first()->posts_count);
        $this->assertEquals(2, User::first()->posts_count_explicit);
        $this->assertEquals(1, User::first()->posts_count_conditional);
        $this->assertEquals(0, User::first()->posts_count_complex_conditional);
        $this->assertEquals(0, User::first()->comments_count);
        $this->assertEquals(null, Post::first()->first_commented_at);

        $comment = new Comment;
        $comment->user_id = $this->data['user']->id;
        $comment->post_id = $this->data['post']->id;
        $comment->save();

        $this->assertEquals(1, User::first()->comments_count);
        $this->assertEquals(1, User::first()->post_comments_sum);
        $this->assertEquals(1, Post::first()->comments_count);
        $this->assertEquals($comment->createdAt, Post::first()->first_commented_at);
        $this->assertEquals($comment->createdAt, Post::first()->last_commented_at);

        $comment->post_id = $post->id;
        $comment->save();

        $this->assertEquals(0, Post::first()->comments_count);
        $this->assertEquals(1, Post::get()[1]->comments_count);
        $this->assertEquals(0, User::first()->posts_count_complex_conditional);

        $post->weight = 8;
        $post->save();

        $this->assertEquals(0, Post::first()->comments_count);
        $this->assertEquals(1, Post::get()[1]->comments_count);
        $this->assertEquals(1, User::first()->posts_count_complex_conditional);

        $post->delete();

        $this->assertEquals(1, User::first()->posts_count);
        $this->assertEquals(1, User::first()->posts_count_explicit);
        $this->assertEquals(0, User::first()->posts_count_conditional);
        $this->assertEquals(0, User::first()->posts_count_complex_conditional);

        $comment->delete();

        $this->assertEquals(0, User::first()->post_comments_sum);
    }

    public function testItCanHandleNegativeCounts()
    {
        $post = new Post;
        $post->user_id = $this->data['user']->id;
        $post->visible = false;
        $post->save();

        $comment = new Comment;
        $comment->user_id = $this->data['user']->id;
        $comment->post_id = $this->data['post']->id;
        $comment->save();

        $this->assertEquals(1, Post::first()->comments_count);

        $comment->delete();

        $this->assertEquals(0, Post::first()->comments_count);
        $this->assertEquals(null, Post::first()->first_commented_at);
        $this->assertEquals(null, Post::first()->last_commented_at);

        $secondComment = new Comment;
        $secondComment->user_id = $this->data['user']->id;
        $secondComment->post_id = $this->data['post']->id;
        $secondComment->save();

        $this->assertEquals(1, Post::first()->comments_count);
        $this->assertEquals($secondComment->createdAt, Post::first()->first_commented_at);
        $this->assertEquals($secondComment->createdAt, Post::first()->last_commented_at);
        
        $comment->restore();

        $this->assertEquals(2, Post::first()->comments_count);
        $this->assertEquals($comment->createdAt, Post::first()->first_commented_at);
        $this->assertEquals($secondComment->createdAt, Post::first()->last_commented_at);
    }

    private function setupUserAndPost()
    {
        $user = new User;
        $user->first_name = 'Kirk';
        $user->last_name = 'Bushell';
        $user->save();

        $post = new Post;
        $post->user_id = $user->id;
        $post->visible = false;
        $post->save();

        return compact('user', 'post');
    }
}
