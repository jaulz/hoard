<?php
namespace Tests\Acceptance;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jaulz\Hoard\HoardSchema;
use Jaulz\Hoard\HoardServiceProvider;
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

    protected $data = [];
    
    public function setUp(): void
    {
        parent::setUp();

        $serviceProvider = new HoardServiceProvider($this->app);
        $serviceProvider->boot();

        $this->app->useDatabasePath(implode(DIRECTORY_SEPARATOR, [__DIR__, '..', '..', 'database']));
        // $this->runDatabaseMigrations();
        $this->migrate();

        $this->init();
    }

    private function migrate()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('sequence');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_sequence')->nullable();
            $table->string('slug')->nullable();
            $table->boolean('visible')->default(false);
            $table->integer('weight')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_sequence');
            $table->integer('post_id');
            $table->boolean('visible')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->increments('id');
            $table->morphs('taggable');
            $table->integer('tag_id');
            $table->integer('weight')->default(0);
            $table->timestamps();
        });

        Schema::create('images', function (Blueprint $table) {
            $table->increments('id');
            $table->string('source');
            $table->morphs('imageable');
            $table->timestamps();
        });

        HoardSchema::init();

        HoardSchema::create('posts', 'default', function (Blueprint $table) {
            $table->integer('comments_count')->default(0)->nullable();
            $table->jsonb('comments_ids')->default()->nullable();
            $table->jsonb('comments_numeric_ids')->default()->nullable();
            $table->integer('tags_count')->default(0)->nullable();
            $table->integer('important_tags_count')->default(0)->nullable();
            $table->integer('images_count')->default(0)->nullable();
            $table->timestamp('first_commented_at')->nullable();
            $table->timestamp('last_commented_at')->nullable();

            $table->hoard('comments_count')->aggregate('comments', 'COUNT', 'id', [
                ['deleted_at', 'IS', null]
            ]);
            $table->hoard('last_commented_at')->aggregate('comments', 'MAX', 'created_at')->withoutSoftDeletes();
            $table->hoard('first_commented_at')->aggregate('comments', 'MIN', 'created_at')->withoutSoftDeletes();
            $table->hoard('comments_ids')->aggregate('comments', 'JSONB_AGG', 'id')->withoutSoftDeletes();
            $table->hoard('comments_numeric_ids')->aggregate('comments',  'JSONB_AGG', 'id')->withoutSoftDeletes()->type('numeric');

            $table->hoard('tags_count')->aggregate('taggables', 'COUNT', 'id')->viaMorph('taggable', Post::class);
            $table->hoard('important_tags_count')->aggregate('taggables', 'COUNT', 'id',  [
                ['weight', '>', 5],
            ])->viaMorph('taggable', Post::class);

            $table->hoard('images_count')->aggregate('images', 'COUNT', 'id', [
            ])->viaMorph('imageable', Post::class);
        });

        HoardSchema::create('users', 'default', function (Blueprint $table) {
            $table->timestampTz('copied_created_at')->nullable();
            $table->integer('comments_count')->default(0)->nullable();
            $table->integer('posts_count')->default(0)->nullable();
            $table->integer('posts_count_explicit')->default(0)->nullable();
            $table->integer('posts_count_conditional')->default(0)->nullable();
            $table->integer('posts_count_complex_conditional')->default(0)->nullable();
            $table->integer('images_count')->default(0)->nullable();

            $table->hoard('copied_created_at')->aggregate('users', 'DISTINCT', 'created_at')->viaOwn();
            $table->hoard('comments_count')->aggregate('comments', 'COUNT', 'id',  [
                ['deleted_at', 'IS', null]
            ]);
            $table->hoard('posts_count')->aggregate('posts', 'COUNT', 'id', [
                ['deleted_at', 'IS', null]
            ]);
            $table->hoard('posts_count_explicit')->aggregate('posts', 'COUNT', 'id', [
                ['deleted_at', 'IS', null]
            ]);
            $table->hoard('posts_count_conditional')->aggregate('posts', 'COUNT', 'id',  'visible = true AND deleted_at IS NULL');
            $table->hoard('posts_count_complex_conditional')->aggregate('posts', 'COUNT', 'id',  'visible = true AND weight > 5 AND deleted_at IS NULL');
            $table->hoard('images_count')->aggregate('images', 'COUNT', 'id',  [
                'imageable_type' => User::class,
            ])->viaMorph('imageable', User::class, 'sequence');
        }, 'sequence');

        HoardSchema::create('taggables', 'default', function (Blueprint $table) {
            $table->integer('cached_taggable_count')->default(0)->nullable();
            $table->timestamp('taggable_created_at')->nullable();

            $table->hoard('cached_taggable_count')->aggregate('users', 'COUNT', 'sequence', null, 'sequence')->viaMorphPivot('taggable', User::class, 'sequence');
            $table->hoard('taggable_created_at')->aggregate('users', 'MAX', 'created_at', null, 'sequence')->viaMorphPivot('taggable', User::class, 'sequence');

            $table->hoard('cached_taggable_count')->aggregate('posts', 'COUNT', 'id')->withoutSoftDeletes()->viaMorphPivot('taggable', Post::class);
            $table->hoard('taggable_created_at')->aggregate('posts', 'MAX', 'created_at')->withoutSoftDeletes()->viaMorphPivot('taggable', Post::class);

            $table->hoard('cached_taggable_count')->aggregate('posts', 'COUNT', 'id')->withoutSoftDeletes()->viaMorphPivot('taggable', Image::class);
            $table->hoard('taggable_created_at')->aggregate('posts', 'MAX', 'created_at')->withoutSoftDeletes()->viaMorphPivot('taggable', Image::class);
        });

        HoardSchema::create('tags', 'default', function (Blueprint $table) {
            $table->integer('taggables_count')->default(0)->nullable();
            $table->timestamp('first_created_at')->nullable();
            $table->timestamp('last_created_at')->nullable();

            $table->hoard('taggables_count')->aggregate('taggables', 'SUM', 'cached_taggable_count', null, null, 'default');
            $table->hoard('last_created_at')->aggregate('taggables', 'MAX', 'taggable_created_at', null,null, 'default');
            $table->hoard('first_created_at')->aggregate('taggables', 'MIN', 'taggable_created_at', null, null, 'default');
        });
    }

    public function init()
    {
        $user = new User();
        $user->first_name = 'Kirk';
        $user->last_name = 'Bushell';
        $user->save();

        $post = new Post();
        $post->user_sequence = $user->sequence;
        $post->visible = false;
        $post->save();

        $tag = new Tag();
        $tag->title = 'General';
        $tag->save();

        $this->data = compact('user', 'post', 'tag');
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

        $this->assertEquals($count, count($queryLog));
    }

    public function refresh($model) {
        return $model->forceRefresh();
    }

    public function testDistinct()
    {
        $user = User::first();

        $this->assertNotEmpty($user->copied_created_at);
    }

    public function testSimpleCount()
    {
        $user = User::first();

        $this->assertEquals(1, $user->posts_count);
        $this->assertEquals(1, $user->posts_count_explicit);
    }

    public function testComplexCount()
    {
        $post = new Post;
        $post->user_sequence = $this->data['user']->sequence;
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
        $comment->user_sequence = $this->data['user']->sequence;
        $comment->post_id = $this->data['post']->id;
        $comment->save();

        $this->assertEquals(1, User::first()->comments_count);
        $this->assertEquals(1, $this->refresh($this->data['post'])->comments_count);
        $this->assertEquals($comment->created_at, $this->refresh($this->data['post'])->first_commented_at);
        $this->assertEquals($comment->created_at, $this->refresh($this->data['post'])->last_commented_at);

        $comment->post_id = $post->id;
        $comment->save();

        $this->assertEquals(0, $this->refresh($this->data['post'])->comments_count);
        $this->assertEquals(1, Post::get()[1]->comments_count);
        $this->assertEquals(0, $this->refresh($this->data['user'])->posts_count_complex_conditional);

        $post->weight = 8;
        $post->save();

        $this->assertEquals(0, Post::first()->comments_count);
        $this->assertEquals(1, Post::get()[1]->comments_count);
        $this->assertEquals(1, $this->refresh($this->data['user'])->posts_count_complex_conditional);

        $post->delete();

        $this->assertEquals(1, $this->refresh($this->data['user'])->posts_count);
        $this->assertEquals(1, $this->refresh($this->data['user'])->posts_count_explicit);
        $this->assertEquals(0, $this->refresh($this->data['user'])->posts_count_conditional);
        $this->assertEquals(0, $this->refresh($this->data['user'])->posts_count_complex_conditional);

        $comment->delete();
    }

    public function testNegativeCounts()
    {
        $post = new Post;
        $post->user_sequence = $this->data['user']->sequence;
        $post->visible = false;
        $post->save();

        $comment = new Comment();
        $comment->user_sequence = $this->data['user']->sequence;
        $comment->post_id = $this->data['post']->id;
        $comment->save();

        $this->assertEquals(1, $this->refresh($this->data['post'])->comments_count);

        $comment->delete();

        $this->assertEquals(0, $this->refresh($this->data['post'])->comments_count);
        $this->assertEquals(null, $this->refresh($this->data['post'])->first_commented_at);
        $this->assertEquals(null, $this->refresh($this->data['post'])->last_commented_at);

        $this->refresh($this->data['post'])->refreshHoard();

        $this->assertEquals(0, $this->refresh($this->data['post'])->comments_count);
        $this->assertEquals(null, $this->refresh($this->data['post'])->first_commented_at);
        $this->assertEquals(null, $this->refresh($this->data['post'])->last_commented_at);

        $secondComment = new Comment;
        $secondComment->user_sequence = $this->data['user']->sequence;
        $secondComment->post_id = $this->data['post']->id;
        $secondComment->save();

        $this->assertEquals(1, $this->refresh($this->data['post'])->comments_count);
        $this->assertEquals($secondComment->created_at, $this->refresh($this->data['post'])->first_commented_at);
        $this->assertEquals($secondComment->created_at, $this->refresh($this->data['post'])->last_commented_at);

        $this->refresh($this->data['post'])->refreshHoard();

        $this->assertEquals(1, $this->refresh($this->data['post'])->comments_count);
        $this->assertEquals($secondComment->created_at, $this->refresh($this->data['post'])->first_commented_at);
        $this->assertEquals($secondComment->created_at, $this->refresh($this->data['post'])->last_commented_at);

        $comment->restore();

        $this->assertEquals(2, $this->refresh($this->data['post'])->comments_count);
        $this->assertEquals($comment->created_at, $this->refresh($this->data['post'])->first_commented_at);
        $this->assertEquals($secondComment->created_at, $this->refresh($this->data['post'])->last_commented_at);
    }

    public function testMorphManyCounts()
    {
        $post = $this->data['post'];

        $image = new Image();
        $image->source = 'https://laravel.com/img/logotype.min.svg';
        $image->imageable()->associate($this->data['post']);
        $image->save();

        $this->assertEquals(1, $this->refresh($post)->images_count);
        $this->assertEquals(0, User::first()->images_count);
        
        // Test if we can assign an image to a Tag that doesn't update "images_count" in "tags" table
        $secondImage = new Image();
        $secondImage->source = 'https://laravel.com/img/logotype.min.svg';
        $secondImage->imageable()->associate($this->data['tag']);
        $secondImage->save();

        $this->assertEquals(1, $this->refresh($post)->images_count);
        $this->assertEquals(0, User::first()->images_count);

        $thirdImage = new Image();
        $thirdImage->source = 'https://laravel.com/img/logotype.min.svg';
        $thirdImage->imageable()->associate($this->data['user']);
        $thirdImage->save();

        $this->assertEquals(1, $this->refresh($post)->images_count);
        $this->assertEquals(1, User::first()->images_count);

        DB::raw('UPDATE posts_cache SET images_count WHERE id = ' . $this->data['post']->id);

        $post->refreshHoard();
        
        $this->assertEquals(1, $this->refresh($post)->images_count);
        $this->assertEquals(1, User::first()->images_count);

        $image->delete();

        $this->assertEquals(0, $this->refresh($post)->images_count);
        $this->assertEquals(1, User::first()->images_count);

        $secondImage->delete();

        $this->assertEquals(0, $this->refresh($post)->images_count);
        $this->assertEquals(1, User::first()->images_count);

        $thirdImage = new Image();
        $thirdImage->source = 'https://laravel.com/img/logotype.min.svg';
        $thirdImage->imageable_type = Post::class;
        $thirdImage->imageable_id = $this->data['post']['id'];
        $thirdImage->save();

        $this->assertEquals(1, $this->refresh($post)->images_count);
    }

    public function testMorphToMany()
    {
        $post = $this->data['post'];

        $this->startQueryLog();
        $post->tags()->attach($this->data['tag']->id, [
            'weight' => 1,
        ]);

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(1, User::first()->posts_count);
        $this->assertEquals(1, Tag::first()->taggables_count);
        $this->assertEquals(1, $this->refresh($post)->tags_count);
        $this->assertEquals(0, $this->refresh($post)->important_tags_count);
        $this->assertEquals($this->refresh($post)->created_at, $this->refresh($this->data['tag'])->first_created_at);
        $this->assertEquals($this->refresh($post)->created_at, $this->refresh($this->data['tag'])->last_created_at);

        $secondTag = new Tag();
        $secondTag->title = 'Updates';
        $secondTag->save();
        
        $this->startQueryLog();
        $post->tags()->attach($secondTag->id, [
            'weight' => 10,
        ]);

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(1, User::first()->posts_count);
        $this->assertEquals(1, $this->refresh($this->data['tag'])->taggables_count);
        $this->assertEquals(2, $this->refresh($post)->tags_count);
        $this->assertEquals(1, $this->refresh($post)->important_tags_count);
        $this->assertEquals($this->refresh($post)->created_at, $this->refresh($this->data['tag'])->first_created_at);
        $this->assertEquals($this->refresh($post)->created_at, $this->refresh($this->data['tag'])->last_created_at);

        Carbon::setTestNow(Carbon::now()->addSecond());
        $secondPost = new Post;
        $secondPost->user_sequence = $this->data['user']->sequence;
        $secondPost->visible = false;
        $secondPost->save();

        $this->startQueryLog();
        $secondPost->tags()->attach($this->data['tag']->id, [
            'weight' => 3,
        ]);

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(2, $this->refresh($this->data['tag'])->taggables_count);
        $this->assertEquals(2, $this->refresh($post)->tags_count);
        $this->assertEquals(1, $this->refresh($post)->important_tags_count);
        $this->assertEquals(1, $this->refresh($secondPost)->tags_count);
        $this->assertEquals(0, $this->refresh($secondPost)->important_tags_count);
        $this->assertEquals($secondPost->created_at, $this->refresh($this->data['tag'])->last_created_at);
        $this->assertEquals($post->created_at, $this->refresh($this->data['tag'])->first_created_at);

        $this->startQueryLog();
        $post->refreshHoard();

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(2, $this->refresh($this->data['tag'])->taggables_count);
        $this->assertEquals(2, $this->refresh($post)->tags_count);
        $this->assertEquals(1, $this->refresh($post)->important_tags_count);
        $this->assertEquals(1, $this->refresh($secondPost)->tags_count);
        $this->assertEquals(0, $this->refresh($secondPost)->important_tags_count);
        $this->assertEquals($secondPost->created_at, $this->refresh($this->data['tag'])->last_created_at);
        $this->assertEquals($post->created_at, $this->refresh($this->data['tag'])->first_created_at);

        $this->startQueryLog();
        $post->tags()->detach($this->data['tag']->id);

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(1, $this->refresh($this->data['tag'])->taggables_count);
        $this->assertEquals(1, $this->refresh($post)->tags_count);
        $this->assertEquals(1, $this->refresh($post)->important_tags_count);
        $this->assertEquals(1, $this->refresh($secondPost)->tags_count);
        // $this->assertEquals(Taggable::orderBy('created_at', 'desc')->first()->created_at, $this->refresh($this->data['tag'])->last_created_at);
        // $this->assertEquals(Taggable::orderBy('created_at', 'asc')->first()->created_at, $this->refresh($this->data['tag'])->first_created_at);

        $this->startQueryLog();
        $post->tags()->attach($this->data['tag']->id, [
            'weight' => 3,
        ]);

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(2, $this->refresh($this->data['tag'])->taggables_count);
        $this->assertEquals(2, $this->refresh($post)->tags_count);
        $this->assertEquals(1, $this->refresh($post)->important_tags_count);
        $this->assertEquals(1, $this->refresh($secondPost)->tags_count);
        $this->assertEquals($secondPost->created_at, $this->refresh($this->data['tag'])->last_created_at);
        $this->assertEquals($post->created_at, $this->refresh($this->data['tag'])->first_created_at);

        $this->startQueryLog();
        $post->delete();

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(1, $this->refresh($this->data['tag'])->taggables_count);
        $this->assertEquals(1, $this->refresh($secondPost)->tags_count);
        $this->assertEquals($secondPost->created_at, $this->refresh($this->data['tag'])->last_created_at);
        $this->assertEquals($secondPost->created_at, $this->refresh($this->data['tag'])->first_created_at);

        $this->startQueryLog();
        $post->restore();

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(2, $this->refresh($this->data['tag'])->taggables_count);
        $this->assertEquals(2, $this->refresh($post)->tags_count);
        $this->assertEquals(1, $this->refresh($post)->important_tags_count);
        $this->assertEquals(1, $this->refresh($secondPost)->tags_count);
        $this->assertEquals($secondPost->created_at, $this->refresh($this->data['tag'])->last_created_at);
        $this->assertEquals($post->created_at, $this->refresh($this->data['tag'])->first_created_at);

        $this->startQueryLog();
        $post->tags()->detach();

        $this->assertQueryLogCountEquals(1);
        // NOTE: detach (without arguments) does not trigger any events so we cannot update the cache
        $this->assertEquals($this->native ? 1 : 2, $this->refresh($this->data['tag'])->taggables_count);

        $tag = $this->refresh($this->data['tag']);
        $this->startQueryLog();
        $tag->refreshHoard();

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(1, $this->refresh($this->data['tag'])->taggables_count);

        $image = new Image();
        $image->source = 'https://laravel.com/img/logotype.min.svg';
        $image->imageable()->associate($this->data['post']);
        $image->save();

        $this->startQueryLog();
        $image->tags()->attach($this->data['tag']->id, [
            'weight' => 3
        ]);

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(2, $this->refresh($this->data['tag'])->taggables_count);

        $tag = $this->refresh($this->data['tag']);
        $this->startQueryLog();
        $tag->refreshHoard();

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(2, $this->refresh($this->data['tag'])->taggables_count);
    }

    public function testMorphByMany()
    {
        $tag = $this->data['tag'];
        $post = $this->data['post'];

        $this->startQueryLog();
        $tag->posts()->attach($post->id, [
            'weight' => 1,
        ]);

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(1, User::first()->posts_count);
        $this->assertEquals(1, $this->refresh($tag)->taggables_count);
        $this->assertEquals(1, $this->refresh($post)->tags_count);
        $this->assertEquals($this->refresh($tag)->first_created_at, $post->created_at);
        $this->assertEquals($this->refresh($tag)->last_created_at, $post->created_at);

        Carbon::setTestNow(Carbon::now()->addSecond());
        $secondPost = new Post;
        $secondPost->user_sequence = $this->data['user']->sequence;
        $secondPost->visible = false;
        $secondPost->save();

        $this->startQueryLog();
        $tag->posts()->attach($secondPost->id, [
            'weight' => 1,
        ]);

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(2, User::first()->posts_count);
        $this->assertEquals(2, $this->refresh($tag)->taggables_count);
        $this->assertEquals(1, $this->refresh($post)->tags_count);
        $this->assertEquals(1, $this->refresh($secondPost)->tags_count);
        $this->assertEquals($this->refresh($tag)->first_created_at, $post->created_at);
        $this->assertEquals($this->refresh($tag)->last_created_at, $secondPost->created_at);

        $this->startQueryLog();
        $tag->posts()->detach($this->data['post']->id);

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(2, User::first()->posts_count);
        $this->assertEquals(1, $this->refresh($tag)->taggables_count);
        $this->assertEquals(0, $this->refresh($post)->tags_count);
        $this->assertEquals(1, $this->refresh($secondPost)->tags_count);
        $this->assertEquals($this->refresh($tag)->first_created_at, $secondPost->created_at);
        $this->assertEquals($this->refresh($tag)->last_created_at, $secondPost->created_at);
    }

    public function testPivot()
    {
        $tag = $this->data['tag'];
        $post = $this->data['post'];

        $this->startQueryLog();
        $tag->posts()->attach($post->id, [
            'weight' => 1,
        ]);

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(1, $this->refresh($tag)->taggables_count);
        $this->assertEquals(1, $this->refresh($post)->tags_count);
        $this->assertEquals($this->refresh($tag)->first_created_at, $post->created_at);
        $this->assertEquals($this->refresh($tag)->last_created_at, $post->created_at);

        $taggable = Taggable::first();
        $this->startQueryLog();
        $taggable->delete();

        $this->assertQueryLogCountEquals(1);
        $this->assertEquals(0, $this->refresh($tag)->taggables_count);
        $this->assertEquals(0, $this->refresh($post)->tags_count);
        $this->assertEquals($this->refresh($tag)->first_created_at, null);
        $this->assertEquals($this->refresh($tag)->last_created_at, null);
    }
}
