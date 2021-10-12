<?php
namespace Tests\Acceptance;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jaulz\Hoard\HoardServiceProvider;
use Jaulz\Hoard\Support\Hoard;
use Orchestra\Testbench\Concerns\CreatesApplication;
use Orchestra\Testbench\TestCase;
use Tests\Acceptance\Models\Comment;
use Tests\Acceptance\Models\Image;
use Tests\Acceptance\Models\Post;
use Tests\Acceptance\Models\Tag;
use Tests\Acceptance\Models\Taggable;
use Tests\Acceptance\Models\User;

class AcceptanceTestCase extends TestCase
{
    use RefreshDatabase;
    use DatabaseMigrations;

    protected $native = false;

    protected $data = [];
    
    public function setUp(): void
    {
        parent::setUp();

        $serviceProvider = new HoardServiceProvider($this->app);
        $serviceProvider->boot();

        $this->app->useDatabasePath(implode(DIRECTORY_SEPARATOR, [__DIR__, '..', '..', 'database']));
        $this->runDatabaseMigrations();
        $this->migrate();
        
        $this->init();
    }

    public function init()
    {
        Hoard::$enabled = !$this->native;

        $user = new User();
        $user->first_name = 'Kirk';
        $user->last_name = 'Bushell';
        $user->save();

        $post = new Post();
        $post->user_id = $user->id;
        $post->visible = false;
        $post->save();

        $tag = new Tag();
        $tag->title = 'General';
        $tag->save();

        $this->data =  compact('user', 'post', 'tag');
    }

    private function migrate()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('slug')->nullable();
            $table->integer('comments_count')->default(0)->nullable();
            $table->integer('posts_count')->default(0)->nullable();
            $table->integer('posts_count_explicit')->default(0)->nullable();
            $table->integer('posts_count_conditional')->default(0)->nullable();
            $table->integer('posts_count_complex_conditional')->default(0)->nullable();
            $table->integer('post_comments_sum')->default(0)->nullable();
            $table->integer('images_count')->default(0)->nullable();
            $table->timestamps();

            if ($this->native) {
                $table->hoard('id', 'taggables', 'taggable_id', 'taggable_count', 'COUNT', 'id', [], [
                    'taggable_type' => User::class,
                ]);
                $table->hoard('id', 'taggables', 'taggable_id', 'taggable_created_at', 'MAX', 'created_at', [], [
                    'taggable_type' => User::class,
                ]);
            }
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->nullable();
            $table->string('slug')->nullable();
            $table->integer('comments_count')->default(0)->nullable();
            $table->integer('tags_count')->default(0)->nullable();
            $table->integer('important_tags_count')->default(0)->nullable();
            $table->integer('images_count')->default(0)->nullable();
            $table->boolean('visible')->default(false);
            $table->timestamp('first_commented_at')->nullable();
            $table->timestamp('last_commented_at')->nullable();
            $table->integer('weight')->default(1);
            $table->softDeletes();
            $table->timestamps();

            if ($this->native) {
                $table->hoard('user_id', 'users', 'id', 'posts_count', 'COUNT', 'id')->withoutSoftDeletes();
                $table->hoard('user_id', 'users', 'id', 'posts_count_explicit', 'COUNT', 'id')->withoutSoftDeletes();
                $table->hoard('user_id', 'users', 'id', 'posts_count_conditional', 'COUNT', 'id', 'visible = true')->withoutSoftDeletes();
                $table->hoard('user_id', 'users', 'id', 'posts_count_complex_conditional', 'COUNT', 'id', 'visible = true AND weight > 5')->withoutSoftDeletes();
                $table->hoard('id', 'taggables', 'taggable_id', 'taggable_count', 'COUNT', 'id', [], [
                    'taggable_type' => Post::class,
                ])->withoutSoftDeletes();
                $table->hoard('id', 'taggables', 'taggable_id', 'taggable_created_at', 'MAX', 'created_at', [], [
                    'taggable_type' => Post::class,
                ])->withoutSoftDeletes();
            }
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('post_id');
            $table->boolean('visible')->default(false);
            $table->timestamps();
            $table->softDeletes();

            if ($this->native) {
                $table->hoard('user_id', 'users', 'id', 'comments_count', 'COUNT', 'id')->withoutSoftDeletes();
                $table->hoard('post_id', 'posts', 'id', 'comments_count', 'COUNT', 'id')->withoutSoftDeletes();
                $table->hoard('post_id', 'posts', 'id', 'last_commented_at', 'MAX', 'created_at')->withoutSoftDeletes();
                $table->hoard('post_id', 'posts', 'id', 'first_commented_at', 'MIN', 'created_at')->withoutSoftDeletes();
            }
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->integer('taggables_count')->default(0)->nullable();
            $table->timestamp('first_created_at')->nullable();
            $table->timestamp('last_created_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->increments('id');
            $table->morphs('taggable');
            $table->integer('tag_id');
            $table->integer('weight')->default(0);
            $table->integer('taggable_count')->default(1)->nullable();
            $table->timestamp('taggable_created_at')->nullable();
            $table->timestamps();

            if ($this->native) {
                $table->hoard('tag_id', 'tags', 'id', 'taggables_count', 'SUM', 'taggable_count', 'taggable_count > 0');
                $table->hoard('taggable_id', 'posts', 'id', 'tags_count', 'COUNT', 'id', 'taggable_type = \'' . Post::class . '\'');
                $table->hoard('taggable_id', 'posts', 'id', 'important_tags_count', 'COUNT', 'id', [
                    ['taggable_type', '=', Post::class],
                    ['weight', '>', 5],
                ]);
                $table->hoard('tag_id', 'tags', 'id', 'last_created_at', 'MAX', 'taggable_created_at');
                $table->hoard('tag_id', 'tags', 'id', 'first_created_at', 'MIN', 'taggable_created_at');
            }
        });

        Schema::create('images', function (Blueprint $table) {
            $table->increments('id');
            $table->string('source');
            $table->morphs('imageable');
            $table->timestamps();

            if ($this->native) {
                $table->hoard('imageable_id', 'users', 'id', 'images_count', 'COUNT', 'id', [
                    'imageable_type' => User::class,
                ]);
                $table->hoard('imageable_id', 'posts', 'id', 'images_count', 'COUNT', 'id', [
                    ['imageable_type', '=', Post::class],
                ]);
            }
        });
    }
    
    protected function debug() {
        DB::listen(function ($query) {
            dump([
                $query->sql,
                $query->bindings,
                $query->time
            ]);
        });
    }

    public function startQueryLog()
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
    }

    public function stopQueryLog()
    {
        $queryLog = DB::getQueryLog();
        DB::flushQueryLog();
        DB::disableQueryLog();

        return $queryLog;
    }

    public function assertQueryLogCountEquals($count) {
        $queryLog = $this->stopQueryLog();

        $this->assertEquals($this->native ? 1 : $count, count($queryLog));
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
        $this->assertEquals(1, $this->data['post']->refresh()->comments_count);
        $this->assertEquals($comment->created_at, $this->data['post']->refresh()->first_commented_at);
        $this->assertEquals($comment->created_at, $this->data['post']->refresh()->last_commented_at);

        $comment->post_id = $post->id;
        $comment->save();

        $this->assertEquals(0, $this->data['post']->refresh()->comments_count);
        $this->assertEquals(1, Post::get()[1]->comments_count);
        $this->assertEquals(0, $this->data['user']->refresh()->posts_count_complex_conditional);

        $post->weight = 8;
        $post->save();

        $this->assertEquals(0, Post::first()->comments_count);
        $this->assertEquals(1, Post::get()[1]->comments_count);
        $this->assertEquals(1, $this->data['user']->refresh()->posts_count_complex_conditional);

        $post->delete();

        $this->assertEquals(1, $this->data['user']->refresh()->posts_count);
        $this->assertEquals(1, $this->data['user']->refresh()->posts_count_explicit);
        $this->assertEquals(0, $this->data['user']->refresh()->posts_count_conditional);
        $this->assertEquals(0, $this->data['user']->refresh()->posts_count_complex_conditional);

        $comment->delete();

        // $this->assertEquals(0, User::first()->post_comments_sum);
    }

    public function testNegativeCounts()
    {
        $post = new Post;
        $post->user_id = $this->data['user']->id;
        $post->visible = false;
        $post->save();

        $comment = new Comment();
        $comment->user_id = $this->data['user']->id;
        $comment->post_id = $this->data['post']->id;
        $comment->save();

        $this->assertEquals(1, $this->data['post']->refresh()->comments_count);

        $comment->delete();

        $this->assertEquals(0, $this->data['post']->refresh()->comments_count);
        $this->assertEquals(null, $this->data['post']->refresh()->first_commented_at);
        $this->assertEquals(null, $this->data['post']->refresh()->last_commented_at);

        $this->data['post']->refresh()->refreshHoard($this->native);

        $this->assertEquals(0, $this->data['post']->refresh()->comments_count);
        $this->assertEquals(null, $this->data['post']->refresh()->first_commented_at);
        $this->assertEquals(null, $this->data['post']->refresh()->last_commented_at);

        $secondComment = new Comment;
        $secondComment->user_id = $this->data['user']->id;
        $secondComment->post_id = $this->data['post']->id;
        $secondComment->save();

        $this->assertEquals(1, $this->data['post']->refresh()->comments_count);
        $this->assertEquals($secondComment->created_at, $this->data['post']->refresh()->first_commented_at);
        $this->assertEquals($secondComment->created_at, $this->data['post']->refresh()->last_commented_at);

        $this->data['post']->refresh()->refreshHoard($this->native);

        $this->assertEquals(1, $this->data['post']->refresh()->comments_count);
        $this->assertEquals($secondComment->created_at, $this->data['post']->refresh()->first_commented_at);
        $this->assertEquals($secondComment->created_at, $this->data['post']->refresh()->last_commented_at);

        $comment->restore();

        $this->assertEquals(2, $this->data['post']->refresh()->comments_count);
        $this->assertEquals($comment->created_at, $this->data['post']->refresh()->first_commented_at);
        $this->assertEquals($secondComment->created_at, $this->data['post']->refresh()->last_commented_at);
    }

    public function testMorphManyCounts()
    {
        $post = $this->data['post'];

        $image = new Image();
        $image->source = 'https://laravel.com/img/logotype.min.svg';
        $image->imageable()->associate($this->data['post']);
        $image->save();

        $this->assertEquals(1, $post->refresh()->images_count);
        $this->assertEquals(0, User::first()->images_count);
        
        // Test if we can assign an image to a Tag that doesn't update "images_count" in "tags" table
        $secondImage = new Image();
        $secondImage->source = 'https://laravel.com/img/logotype.min.svg';
        $secondImage->imageable()->associate($this->data['tag']);
        $secondImage->save();

        $this->assertEquals(1, $post->refresh()->images_count);
        $this->assertEquals(0, User::first()->images_count);

        $thirdImage = new Image();
        $thirdImage->source = 'https://laravel.com/img/logotype.min.svg';
        $thirdImage->imageable()->associate($this->data['user']);
        $thirdImage->save();

        $this->assertEquals(1, $post->refresh()->images_count);
        $this->assertEquals(1, User::first()->images_count);

        $this->data['post']->images_count = 3;
        $this->data['post']->save();

        $post->refreshHoard($this->native);
        
        $this->assertEquals(1, $post->refresh()->images_count);
        $this->assertEquals(1, User::first()->images_count);

        $image->delete();

        $this->assertEquals(0, $post->refresh()->images_count);
        $this->assertEquals(1, User::first()->images_count);

        $secondImage->delete();

        $this->assertEquals(0, $post->refresh()->images_count);
        $this->assertEquals(1, User::first()->images_count);

        $thirdImage = new Image();
        $thirdImage->source = 'https://laravel.com/img/logotype.min.svg';
        $thirdImage->imageable_type = Post::class;
        $thirdImage->imageable_id = $this->data['post']['id'];
        $thirdImage->save();

        $this->assertEquals(1, $post->refresh()->images_count);
    }

    public function testMorphToMany()
    {
        $post = $this->data['post'];

        $this->startQueryLog();
        $post->tags()->attach($this->data['tag']->id, [
            'weight' => 1,
        ]);

        $this->assertQueryLogCountEquals(4);
        $this->assertEquals(1, User::first()->posts_count);
        $this->assertEquals(1, Tag::first()->taggables_count);
        $this->assertEquals(1, $post->refresh()->tags_count);
        $this->assertEquals(0, $post->refresh()->important_tags_count);
        $this->assertEquals($post->refresh()->created_at, $this->data['tag']->refresh()->first_created_at);
        $this->assertEquals($post->refresh()->created_at, $this->data['tag']->refresh()->last_created_at);

        $secondTag = new Tag();
        $secondTag->title = 'Updates';
        $secondTag->save();
        
        $this->startQueryLog();
        $post->tags()->attach($secondTag->id, [
            'weight' => 10,
        ]);

        $this->assertQueryLogCountEquals(4);
        $this->assertEquals(1, User::first()->posts_count);
        $this->assertEquals(1, $this->data['tag']->refresh()->taggables_count);
        $this->assertEquals(2, $post->refresh()->tags_count);
        $this->assertEquals(1, $post->refresh()->important_tags_count);
        $this->assertEquals($post->refresh()->created_at, $this->data['tag']->refresh()->first_created_at);
        $this->assertEquals($post->refresh()->created_at, $this->data['tag']->refresh()->last_created_at);

        Carbon::setTestNow(Carbon::now()->addSecond());
        $secondPost = new Post;
        $secondPost->user_id = $this->data['user']->id;
        $secondPost->visible = false;
        $secondPost->save();

        $this->startQueryLog();
        $secondPost->tags()->attach($this->data['tag']->id, [
            'weight' => 3,
        ]);

        $this->assertQueryLogCountEquals(4);
        $this->assertEquals(2, $this->data['tag']->refresh()->taggables_count);
        $this->assertEquals(2, $post->refresh()->tags_count);
        $this->assertEquals(1, $post->refresh()->important_tags_count);
        $this->assertEquals(1, $secondPost->refresh()->tags_count);
        $this->assertEquals(0, $secondPost->refresh()->important_tags_count);
        $this->assertEquals($secondPost->created_at, $this->data['tag']->refresh()->last_created_at);
        $this->assertEquals($post->created_at, $this->data['tag']->refresh()->first_created_at);

        $this->startQueryLog();
        $post->refreshHoard($this->native);

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(2, $this->data['tag']->refresh()->taggables_count);
        $this->assertEquals(2, $post->refresh()->tags_count);
        $this->assertEquals(1, $post->refresh()->important_tags_count);
        $this->assertEquals(1, $secondPost->refresh()->tags_count);
        $this->assertEquals(0, $secondPost->refresh()->important_tags_count);
        $this->assertEquals($secondPost->created_at, $this->data['tag']->refresh()->last_created_at);
        $this->assertEquals($post->created_at, $this->data['tag']->refresh()->first_created_at);

        $this->startQueryLog();
        $post->tags()->detach($this->data['tag']->id);

        $this->assertQueryLogCountEquals(5);
        $this->assertEquals(1, $this->data['tag']->refresh()->taggables_count);
        $this->assertEquals(1, $post->refresh()->tags_count);
        $this->assertEquals(1, $post->refresh()->important_tags_count);
        $this->assertEquals(1, $secondPost->refresh()->tags_count);
        // $this->assertEquals(Taggable::orderBy('created_at', 'desc')->first()->created_at, $this->data['tag']->refresh()->last_created_at);
        // $this->assertEquals(Taggable::orderBy('created_at', 'asc')->first()->created_at, $this->data['tag']->refresh()->first_created_at);

        $this->startQueryLog();
        $post->tags()->attach($this->data['tag']->id, [
            'weight' => 3,
        ]);

        $this->assertQueryLogCountEquals(4);
        $this->assertEquals(2, $this->data['tag']->refresh()->taggables_count);
        $this->assertEquals(2, $post->refresh()->tags_count);
        $this->assertEquals(1, $post->refresh()->important_tags_count);
        $this->assertEquals(1, $secondPost->refresh()->tags_count);
        $this->assertEquals($secondPost->created_at, $this->data['tag']->refresh()->last_created_at);
        $this->assertEquals($post->created_at, $this->data['tag']->refresh()->first_created_at);

        $this->startQueryLog();
        $post->delete();

        $this->assertQueryLogCountEquals(5);
        $this->assertEquals(1, $this->data['tag']->refresh()->taggables_count);
        $this->assertEquals(1, $secondPost->refresh()->tags_count);
        $this->assertEquals($secondPost->created_at, $this->data['tag']->refresh()->last_created_at);
        $this->assertEquals($secondPost->created_at, $this->data['tag']->refresh()->first_created_at);

        $this->startQueryLog();
        $post->restore();

        $this->assertQueryLogCountEquals(5);
        $this->assertEquals(2, $this->data['tag']->refresh()->taggables_count);
        $this->assertEquals(2, $post->refresh()->tags_count);
        $this->assertEquals(1, $post->refresh()->important_tags_count);
        $this->assertEquals(1, $secondPost->refresh()->tags_count);
        $this->assertEquals($secondPost->created_at, $this->data['tag']->refresh()->last_created_at);
        $this->assertEquals($post->created_at, $this->data['tag']->refresh()->first_created_at);

        $this->startQueryLog();
        $post->tags()->detach();

        $this->assertQueryLogCountEquals(1);
        // NOTE: detach (without arguments) does not trigger any events so we cannot update the cache
        $this->assertEquals($this->native ? 1 : 2, $this->data['tag']->refresh()->taggables_count);

        $tag = $this->data['tag']->refresh();
        $this->startQueryLog();
        $tag->refreshHoard($this->native);

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(1, $this->data['tag']->refresh()->taggables_count);

        $image = new Image();
        $image->source = 'https://laravel.com/img/logotype.min.svg';
        $image->imageable()->associate($this->data['post']);
        $image->save();

        $this->startQueryLog();
        $image->tags()->attach($this->data['tag']->id, [
            'weight' => 3
        ]);

        $this->assertQueryLogCountEquals(3);
        $this->assertEquals(2, $this->data['tag']->refresh()->taggables_count);

        $tag = $this->data['tag']->refresh();
        $this->startQueryLog();
        $tag->refreshHoard($this->native);

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(2, $this->data['tag']->refresh()->taggables_count);
    }

    public function testMorphByMany()
    {
        $tag = $this->data['tag'];
        $post = $this->data['post'];

        $this->startQueryLog();
        $tag->posts()->attach($post->id, [
            'weight' => 1,
        ]);

        $this->assertQueryLogCountEquals(4);
        $this->assertEquals(1, User::first()->posts_count);
        $this->assertEquals(1, $tag->refresh()->taggables_count);
        $this->assertEquals(1, $post->refresh()->tags_count);
        $this->assertEquals($tag->refresh()->first_created_at, $post->created_at);
        $this->assertEquals($tag->refresh()->last_created_at, $post->created_at);

        Carbon::setTestNow(Carbon::now()->addSecond());
        $secondPost = new Post;
        $secondPost->user_id = $this->data['user']->id;
        $secondPost->visible = false;
        $secondPost->save();

        $this->startQueryLog();
        $tag->posts()->attach($secondPost->id, [
            'weight' => 1,
        ]);

        $this->assertQueryLogCountEquals(4);
        $this->assertEquals(2, User::first()->posts_count);
        $this->assertEquals(2, $tag->refresh()->taggables_count);
        $this->assertEquals(1, $post->refresh()->tags_count);
        $this->assertEquals(1, $secondPost->refresh()->tags_count);
        $this->assertEquals($tag->refresh()->first_created_at, $post->created_at);
        $this->assertEquals($tag->refresh()->last_created_at, $secondPost->created_at);

        $this->startQueryLog();
        $tag->posts()->detach($this->data['post']->id);

        $this->assertQueryLogCountEquals(5);
        $this->assertEquals(2, User::first()->posts_count);
        $this->assertEquals(1, $tag->refresh()->taggables_count);
        $this->assertEquals(0, $post->refresh()->tags_count);
        $this->assertEquals(1, $secondPost->refresh()->tags_count);
        $this->assertEquals($tag->refresh()->first_created_at, $secondPost->created_at);
        $this->assertEquals($tag->refresh()->last_created_at, $secondPost->created_at);
    }

    public function testPivot()
    {
        $tag = $this->data['tag'];
        $post = $this->data['post'];

        $this->startQueryLog();
        $tag->posts()->attach($post->id, [
            'weight' => 1,
        ]);

        $this->assertQueryLogCountEquals(4);
        $this->assertEquals(1, $tag->refresh()->taggables_count);
        $this->assertEquals(1, $post->refresh()->tags_count);
        $this->assertEquals($tag->refresh()->first_created_at, $post->created_at);
        $this->assertEquals($tag->refresh()->last_created_at, $post->created_at);

        $taggable = Taggable::first();
        $this->startQueryLog();
        $taggable->delete();

        $this->assertQueryLogCountEquals(6);
        $this->assertEquals(0, $tag->refresh()->taggables_count);
        $this->assertEquals(0, $post->refresh()->tags_count);
        $this->assertEquals($tag->refresh()->first_created_at, null);
        $this->assertEquals($tag->refresh()->last_created_at, null);
    }
}
