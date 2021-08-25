<?php

namespace Tests\Acceptance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Acceptance\Models\Comment;
use Tests\Acceptance\Models\Image;
use Tests\Acceptance\Models\Post;
use Tests\Acceptance\Models\Tag;
use Tests\Acceptance\Models\Taggable;
use Tests\Acceptance\Models\User;

class CountCacheTest extends AcceptanceTestCase
{
    private $data = [];

    public function init()
    {
        $user = new User;
        $user->first_name = 'Kirk';
        $user->last_name = 'Bushell';
        $user->save();

        $post = new Post;
        $post->user_id = $user->id;
        $post->visible = false;
        $post->save();

        $tag = new Tag();
        $tag->title = 'General';
        $tag->save();

        $this->data =  compact('user', 'post', 'tag');
    }

    public function testCount()
    {
        $user = User::first();

        $this->assertEquals(1, $user->posts_count);
        $this->assertEquals(1, $user->posts_count_explicit);
    }

    public function testComplexCounts()
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
        $this->assertEquals($comment->created_at, Post::first()->first_commented_at);
        $this->assertEquals($comment->created_at, Post::first()->last_commented_at);

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

    public function testNegativeCounts()
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
        $this->assertEquals($secondComment->created_at, Post::first()->first_commented_at);
        $this->assertEquals($secondComment->created_at, Post::first()->last_commented_at);

        $comment->restore();

        $this->assertEquals(2, Post::first()->comments_count);
        $this->assertEquals($comment->created_at, Post::first()->first_commented_at);
        $this->assertEquals($secondComment->created_at, Post::first()->last_commented_at);
    }

    public function testMorphManyCounts()
    {
        $image = new Image();
        $image->source = 'https://laravel.com/img/logotype.min.svg';
        $image->imageable()->associate($this->data['post']);
        $image->save();

        $this->assertEquals(1, Post::first()->images_count);

        $image->delete();

        $this->assertEquals(0, Post::first()->images_count);
    }

    public function testMorphToMany()
    {
        $post = new Post;
        $post->user_id = $this->data['user']->id;
        $post->visible = false;
        $post->save();

        $post->tags()->attach($this->data['tag']->id);

        $this->assertEquals(1, Tag::first()->taggables_count);
        $this->assertEquals($post->created_at, Tag::first()->first_created_at);
        $this->assertEquals($post->created_at, Tag::first()->last_created_at);

        Carbon::setTestNow(Carbon::now()->addSecond());
        $secondPost = new Post;
        $secondPost->user_id = $this->data['user']->id;
        $secondPost->visible = false;
        $secondPost->save();

        $secondPost->tags()->attach($this->data['tag']->id);
        
        $this->assertEquals(2, Tag::first()->taggables_count);
        $this->assertEquals($post->created_at, Tag::first()->first_created_at);
        $this->assertEquals($secondPost->created_at, Tag::first()->last_created_at);

        $post->tags()->detach($this->data['tag']->id);

        $this->assertEquals(1, Tag::first()->taggables_count);
        $this->assertEquals($secondPost->created_at, Tag::first()->first_created_at);
        $this->assertEquals($secondPost->created_at, Tag::first()->last_created_at);

        $post->tags()->attach($this->data['tag']->id);

        $this->assertEquals(2, Tag::first()->taggables_count);
        /*$this->assertEquals($post->created_at, Tag::first()->first_created_at);
        $this->assertEquals($secondPost->created_at, Tag::first()->last_created_at);*/

        $post->delete();

        $this->assertEquals(1, Tag::first()->taggables_count);
        /*$this->assertEquals($secondPost->created_at, Tag::first()->first_created_at);
        $this->assertEquals($secondPost->created_at, Tag::first()->last_created_at);*/

        $post->restore();

        $this->assertEquals(2, Tag::first()->taggables_count);
        /*$this->assertEquals($post->created_at, Tag::first()->first_created_at);
        $this->assertEquals($secondPost->created_at, Tag::first()->last_created_at);*/

        // NOTE: detach (without arguments) does not trigger any events so we cannot update the cache
        $post->tags()->detach();

        $this->assertEquals(2, Tag::first()->taggables_count);

        Tag::first()->rebuildCache();

        $this->assertEquals(1, Tag::first()->taggables_count);

        $image = new Image();
        $image->source = 'https://laravel.com/img/logotype.min.svg';
        $image->imageable()->associate($this->data['post']);
        $image->save();

        $image->tags()->attach($this->data['tag']->id);

        $this->assertEquals(2, Tag::first()->taggables_count);

        Tag::first()->rebuildCache();

        $this->assertEquals(2, Tag::first()->taggables_count);
    }
}
