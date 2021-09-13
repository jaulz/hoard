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
        // $this->assertEquals(1, User::first()->post_comments_sum);
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

        // $this->assertEquals(0, User::first()->post_comments_sum);
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

        Post::first()->refreshHoard();

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

        Post::first()->refreshHoard();

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
        $this->assertEquals(0, User::first()->images_count);
        
        // Test if we can assign an image to a Tag that doesn't update "images_count" in "tags" table
        $secondImage = new Image();
        $secondImage->source = 'https://laravel.com/img/logotype.min.svg';
        $secondImage->imageable()->associate($this->data['tag']);
        $secondImage->save();

        $this->assertEquals(1, Post::first()->images_count);
        $this->assertEquals(0, User::first()->images_count);

        $thirdImage = new Image();
        $thirdImage->source = 'https://laravel.com/img/logotype.min.svg';
        $thirdImage->imageable()->associate($this->data['user']);
        $thirdImage->save();

        $this->assertEquals(1, Post::first()->images_count);
        $this->assertEquals(1, User::first()->images_count);

        $this->data['post']->images_count = 3;
        $this->data['post']->save();

        Post::first()->refreshHoard();
        
        $this->assertEquals(1, Post::first()->images_count);
        $this->assertEquals(1, User::first()->images_count);

        $image->delete();

        $this->assertEquals(0, Post::first()->images_count);
        $this->assertEquals(1, User::first()->images_count);

        $secondImage->delete();

        $this->assertEquals(0, Post::first()->images_count);
        $this->assertEquals(1, User::first()->images_count);

        $thirdImage = new Image();
        $thirdImage->source = 'https://laravel.com/img/logotype.min.svg';
        $thirdImage->imageable_type = Post::class;
        $thirdImage->imageable_id = $this->data['post']['id'];
        $thirdImage->save();

        $this->assertEquals(1, Post::first()->images_count);
    }

    public function testMorphToMany()
    {
        $post = $this->data['post'];

        $this->startQueryLog();
        $post->tags()->attach($this->data['tag']->id, [
            'weight' => 1,
        ]);
        $queryLog = $this->stopQueryLog();

        $this->assertEquals(3, count($queryLog));
        $this->assertEquals(1, User::first()->posts_count);
        $this->assertEquals(1, Tag::first()->taggables_count);
        $this->assertEquals(1, Post::first()->tags_count);
        $this->assertEquals(0, Post::first()->important_tags_count);
        $this->assertEquals($post->created_at, Tag::first()->first_created_at);
        $this->assertEquals($post->created_at, Tag::first()->last_created_at);

        $secondTag = new Tag();
        $secondTag->title = 'Updates';
        $secondTag->save();
        
        $post->tags()->attach($secondTag->id, [
            'weight' => 10,
        ]);

        $this->assertEquals(3, count($queryLog));
        $this->assertEquals(1, User::first()->posts_count);
        $this->assertEquals(1, Tag::first()->taggables_count);
        $this->assertEquals(2, Post::first()->tags_count);
        $this->assertEquals(1, Post::first()->important_tags_count);
        $this->assertEquals($post->created_at, Tag::first()->first_created_at);
        $this->assertEquals($post->created_at, Tag::first()->last_created_at);

        Carbon::setTestNow(Carbon::now()->addSecond());
        $secondPost = new Post;
        $secondPost->user_id = $this->data['user']->id;
        $secondPost->visible = false;
        $secondPost->save();

        $this->startQueryLog();
        $secondPost->tags()->attach($this->data['tag']->id, [
            'weight' => 3,
        ]);
        $queryLog = $this->stopQueryLog();

        $this->assertEquals(3, count($queryLog));
        $this->assertEquals(2, Tag::first()->taggables_count);
        $this->assertEquals(2, Post::first()->tags_count);
        $this->assertEquals(1, Post::first()->important_tags_count);
        $this->assertEquals(1, $secondPost->refresh()->tags_count);
        $this->assertEquals(0, $secondPost->refresh()->important_tags_count);
        $this->assertEquals($secondPost->created_at, Tag::first()->last_created_at);
        $this->assertEquals($post->created_at, Tag::first()->first_created_at);

        $this->startQueryLog();
        $post->tags()->detach($this->data['tag']->id);
        $queryLog = $this->stopQueryLog();

        $this->assertEquals(4, count($queryLog));
        $this->assertEquals(1, Tag::first()->taggables_count);
        $this->assertEquals(1, Post::first()->tags_count);
        $this->assertEquals(1, Post::first()->important_tags_count);
        $this->assertEquals(1, $secondPost->refresh()->tags_count);
        $this->assertEquals($secondPost->created_at, Tag::first()->last_created_at);
        $this->assertEquals($secondPost->created_at, Tag::first()->first_created_at);

        $this->startQueryLog();
        $post->tags()->attach($this->data['tag']->id, [
            'weight' => 3,
        ]);
        $queryLog = $this->stopQueryLog();

        $this->assertEquals(3, count($queryLog));
        $this->assertEquals(2, Tag::first()->taggables_count);
        $this->assertEquals(2, Post::first()->tags_count);
        $this->assertEquals(1, Post::first()->important_tags_count);
        $this->assertEquals(1, $secondPost->refresh()->tags_count);
        $this->assertEquals($secondPost->created_at, Tag::first()->last_created_at);
        $this->assertEquals($post->created_at, Tag::first()->first_created_at);

        $this->startQueryLog();
        $post->delete();
        $queryLog = $this->stopQueryLog();

        $this->assertEquals(5, count($queryLog));
        $this->assertEquals(1, Tag::first()->taggables_count);
        $this->assertEquals(1, $secondPost->refresh()->tags_count);
        $this->assertEquals($secondPost->created_at, Tag::first()->last_created_at);
        $this->assertEquals($secondPost->created_at, Tag::first()->first_created_at);

        $this->startQueryLog();
        $post->restore();
        $queryLog = $this->stopQueryLog();

        $this->assertEquals(5, count($queryLog));
        $this->assertEquals(2, Tag::first()->taggables_count);
        $this->assertEquals(2, Post::first()->tags_count);
        $this->assertEquals(1, Post::first()->important_tags_count);
        $this->assertEquals(1, $secondPost->refresh()->tags_count);
        $this->assertEquals($secondPost->created_at, Tag::first()->last_created_at);
        $this->assertEquals($post->created_at, Tag::first()->first_created_at);

        $this->startQueryLog();
        // NOTE: detach (without arguments) does not trigger any events so we cannot update the cache
        $post->tags()->detach();
        $queryLog = $this->stopQueryLog();

        $this->assertEquals(1, count($queryLog));
        $this->assertEquals(2, Tag::first()->taggables_count);

        $tag = Tag::first();
        $this->startQueryLog();
        $tag->refreshHoard();
        $queryLog = $this->stopQueryLog();

        $this->assertEquals(1, count($queryLog));
        $this->assertEquals(1, Tag::first()->taggables_count);

        $image = new Image();
        $image->source = 'https://laravel.com/img/logotype.min.svg';
        $image->imageable()->associate($this->data['post']);
        $image->save();

        $this->startQueryLog();
        $image->tags()->attach($this->data['tag']->id, [
            'weight' => 3
        ]);
        $queryLog = $this->stopQueryLog();

        $this->assertEquals(3, count($queryLog));
        $this->assertEquals(2, Tag::first()->taggables_count);

        $tag = Tag::first();
        $this->startQueryLog();
        Tag::first()->refreshHoard();
        $queryLog = $this->stopQueryLog();

        $this->assertEquals(2, count($queryLog));
        $this->assertEquals(2, Tag::first()->taggables_count);
    }

    public function testMorphByMany()
    {
        $tag = $this->data['tag'];
        $post = $this->data['post'];

        $this->startQueryLog();
        $tag->posts()->attach($post->id, [
            'weight' => 1,
        ]);
        $queryLog = $this->stopQueryLog();

        $this->assertEquals(3, count($queryLog));
        $this->assertEquals(1, User::first()->posts_count);
        $this->assertEquals(1, Tag::first()->taggables_count);
        $this->assertEquals(1, Post::first()->tags_count);
        $this->assertEquals(Tag::first()->first_created_at, $post->created_at);
        $this->assertEquals(Tag::first()->last_created_at, $post->created_at);

        Carbon::setTestNow(Carbon::now()->addSecond());
        $secondPost = new Post;
        $secondPost->user_id = $this->data['user']->id;
        $secondPost->visible = false;
        $secondPost->save();

        $this->startQueryLog();
        $tag->posts()->attach($secondPost->id, [
            'weight' => 1,
        ]);
        $queryLog = $this->stopQueryLog();

        $this->assertEquals(3, count($queryLog));
        $this->assertEquals(2, User::first()->posts_count);
        $this->assertEquals(2, Tag::first()->taggables_count);
        $this->assertEquals(1, Post::first()->tags_count);
        $this->assertEquals(1, $secondPost->refresh()->tags_count);
        $this->assertEquals(Tag::first()->first_created_at, $post->created_at);
        $this->assertEquals(Tag::first()->last_created_at, $secondPost->created_at);

        $this->startQueryLog();
        $tag->posts()->detach($this->data['post']->id);
        $queryLog = $this->stopQueryLog();

        $this->assertEquals(4, count($queryLog));
        $this->assertEquals(2, User::first()->posts_count);
        $this->assertEquals(1, Tag::first()->taggables_count);
        $this->assertEquals(0, Post::first()->tags_count);
        $this->assertEquals(1, $secondPost->refresh()->tags_count);
        $this->assertEquals(Tag::first()->first_created_at, $secondPost->created_at);
        $this->assertEquals(Tag::first()->last_created_at, $secondPost->created_at);
    }

    public function testPivot()
    {
        $tag = $this->data['tag'];
        $post = $this->data['post'];

        $this->startQueryLog();
        $tag->posts()->attach($post->id, [
            'weight' => 1,
        ]);
        $queryLog = $this->stopQueryLog();

        $this->assertEquals(3, count($queryLog));
        $this->assertEquals(1, Tag::first()->taggables_count);
        $this->assertEquals(1, $post->refresh()->tags_count);
        $this->assertEquals(Tag::first()->first_created_at, $post->created_at);
        $this->assertEquals(Tag::first()->last_created_at, $post->created_at);

        $taggable = Taggable::first();
        $this->startQueryLog();
        $taggable->delete();
        $queryLog = $this->stopQueryLog();

        $this->assertEquals(4, count($queryLog));
        $this->assertEquals(0, Tag::first()->taggables_count);
        $this->assertEquals(0, $post->refresh()->tags_count);
        $this->assertEquals(Tag::first()->first_created_at, null);
        $this->assertEquals(Tag::first()->last_created_at, null);
    }
}
