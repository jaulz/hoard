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
    // use DatabaseMigrations;

    protected $data = [];

    public function setUp(): void
    {
        parent::setUp();

        $serviceProvider = new HoardServiceProvider($this->app);
        $serviceProvider->boot();

        $this->app->useDatabasePath(implode(DIRECTORY_SEPARATOR, [__DIR__, '..', '..', 'database']));
        // $this->runDatabaseMigrations();
        $this->artisan('migrate:fresh');
        $this->migrate();

        $this->init();
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'test');
        $app['config']->set('database.connections.test', array(
            'driver'   => 'pgsql',
            'database' => 'hoard'
        ));
    }

    private function migrate()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('sequence');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('slug')->nullable();
            $table->timestampsTz();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_sequence')->nullable();
            $table->string('slug')->nullable();
            $table->boolean('visible')->default(false);
            $table->float('weight')->default(1);
            $table->softDeletesTz();
            $table->timestampsTz();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_sequence');
            $table->integer('post_id');
            $table->boolean('visible')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->increments('id');
            $table->morphs('taggable');
            $table->integer('tag_id');
            $table->integer('weight')->default(0);
            $table->timestampsTz();
        });

        Schema::create('images', function (Blueprint $table) {
            $table->increments('id');
            $table->string('source');
            $table->morphs('imageable');
            $table->timestampsTz();
        });

        HoardSchema::init();

        HoardSchema::create('posts', 'default', function (Blueprint $table) {
            $table->integer('comments_count')->default(0)->nullable();
            $table->hoard('comments_count')->aggregate('comments', 'COUNT', 'id', [
                ['deleted_at', 'IS', null]
            ]);

            $table->jsonb('comments_ids')->default()->nullable();
            $table->hoard('comments_ids')->aggregate('comments', 'PUSH', 'id')->options([
                'type' => 'string'
            ])->withoutSoftDeletes();

            $table->jsonb('comments_numeric_ids')->default()->nullable();
            $table->hoard('comments_numeric_ids')->aggregate('comments',  'PUSH', 'id')->withoutSoftDeletes()->options([
                'type' => 'number'
            ]);

            $table->integer('tags_count')->default(0)->nullable();
            $table->hoard('tags_count')->aggregate('taggables', 'COUNT', 'id')->viaMorph('taggable', Post::class);

            $table->integer('important_tags_count')->default(0)->nullable();
            $table->hoard('important_tags_count')->aggregate('taggables', 'COUNT', 'id',  [
                ['weight', '>', 5],
            ])->viaMorph('taggable', Post::class);

            $table->integer('images_count')->default(0)->nullable();
            $table->hoard('images_count')->aggregate('images', 'COUNT', 'id', [])->viaMorph('imageable', Post::class);

            $table->hoard('last_commented_at')->aggregate('comments', 'MAX', 'created_at')->withoutSoftDeletes();
            $table->timestamp('first_commented_at')->nullable();

            $table->timestamp('last_commented_at')->nullable();
            $table->hoard('first_commented_at')->aggregate('comments', 'MIN', 'created_at')->withoutSoftDeletes();
        });

        HoardSchema::create('users', 'default', function (Blueprint $table) {
            $table->timestampTz('copied_created_at')->nullable();
            $table->hoard('copied_created_at')->aggregate('users', 'COPY', 'created_at')->viaOwn();

            $table->integer('comments_count')->default(0)->nullable();
            $table->hoard('comments_count')->aggregate('comments', 'COUNT', 'id',  [
                ['deleted_at', 'IS', null]
            ]);

            $table->integer('posts_count')->default(0)->nullable();
            $table->hoard('posts_count')->aggregate('posts', 'COUNT', 'id', [
                ['deleted_at', 'IS', null]
            ]);

            $table->integer('posts_count_explicit')->default(0)->nullable();
            $table->hoard('posts_count_explicit')->aggregate('posts', 'COUNT', 'id', [
                ['deleted_at', 'IS', null]
            ]);

            $table->integer('posts_count_conditional')->default(0)->nullable();
            $table->hoard('posts_count_conditional')->aggregate('posts', 'COUNT', 'id',  'visible = true AND deleted_at IS NULL');

            $table->integer('posts_count_complex_conditional')->default(0)->nullable();
            $table->hoard('posts_count_complex_conditional')->aggregate('posts', 'COUNT', 'id',  'visible = true AND weight > 5 AND deleted_at IS NULL');

            $table->integer('images_count')->default(0)->nullable();
            $table->hoard('images_count')->aggregate('images', 'COUNT', 'id',  [
                'imageable_type' => User::class,
            ])->viaMorph('imageable', User::class, 'sequence');

            $table
                ->double('posts_count_plus_one')
                ->storedAs(
                    DB::raw('posts_count + 1')
                )
                ->always();
            $table->hoard('posts_count_plus_one')->manual();

            $table->integer('asynchronous_posts_weight_sum')->default(0)->nullable();
            $table->hoard('asynchronous_posts_weight_sum')->aggregate('posts', 'SUM', 'weight', [
                ['deleted_at', 'IS', null]
            ])->asynchronous();

            $table->jsonb('grouped_posts_count_by_weekday')->default('{}');
            $table->hoard('grouped_posts_count_by_weekday')->aggregate('posts', 'GROUP', [
                'extract(isodow from created_at)',
                'id'
            ])->withoutSoftDeletes()->options([
                'aggregation_function' => 'count',
            ]);

            $table->jsonb('grouped_posts_weight_by_workingday')->default('{}');
            $table->hoard('grouped_posts_weight_by_workingday')->aggregate('posts', 'GROUP', [
                'extract(isodow from created_at)',
                'weight'
            ])->withoutSoftDeletes()->options([
                'aggregation_function' => 'sum',
                'condition' => 'key::int >= 1 AND key::int <= 5'
            ]);
        }, 'sequence');

        HoardSchema::create('taggables', 'default', function (Blueprint $table) {
            $table->integer('cached_taggable_count')->default(0)->nullable();
            $table->hoard('cached_taggable_count')->aggregate('posts', 'COUNT', 'id')->withoutSoftDeletes()->viaMorphPivot('taggable', Post::class);
            $table->hoard('cached_taggable_count')->aggregate('images', 'COUNT', 'id')->viaMorphPivot('taggable', Image::class);
            $table->hoard('cached_taggable_count')->aggregate('users', 'COUNT', 'sequence', null, 'sequence')->viaMorphPivot('taggable', User::class, 'sequence');

            $table->timestamp('taggable_created_at')->nullable();
            $table->hoard('taggable_created_at')->aggregate('users', 'MAX', 'created_at', null, 'sequence')->viaMorphPivot('taggable', User::class, 'sequence');
            $table->hoard('taggable_created_at')->aggregate('images', 'MAX', 'created_at')->viaMorphPivot('taggable', Image::class);
            $table->hoard('taggable_created_at')->aggregate('posts', 'MAX', 'created_at')->withoutSoftDeletes()->viaMorphPivot('taggable', Post::class);
        });

        HoardSchema::create('tags', 'default', function (Blueprint $table) {
            $table->integer('taggables_count')->default(0)->nullable();
            $table->hoard('taggables_count')->aggregate('taggables', 'SUM', 'cached_taggable_count', null, null, 'default');

            $table->timestamp('first_created_at')->nullable();
            $table->hoard('first_created_at')->aggregate('taggables', 'MIN', 'taggable_created_at', null, null, 'default');

            $table->timestamp('last_created_at')->nullable();
            $table->hoard('last_created_at')->aggregate('taggables', 'MAX', 'taggable_created_at', null, null, 'default');
        });
    }

    public function init()
    {
        $user = new User();
        $user->first_name = 'Julian';
        $user->last_name = 'Hundeloh';
        $user->save();

        $post = new Post();
        $post->user_sequence = $user->sequence;
        $post->visible = false;
        $post->created_at = Carbon::parse('2022-04-26 14:50:00+02');
        $post->save();

        $tag = new Tag();
        $tag->title = 'General';
        $tag->save();

        $this->data = compact('user', 'post', 'tag');
    }

    protected function debug()
    {
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

    public function assertQueryLogCountEquals($count)
    {
        $queryLog = $this->stopQueryLog();

        $this->assertEquals($count, count($queryLog));
    }

    public function refresh($model)
    {
        return $model->forceRefresh();
    }

    public function testCopy()
    {
        $user = User::first();

        $this->assertNotEmpty($user->copied_created_at);

        $user->created_at = new Carbon('2016-01-23');
        $user->save();

        $this->assertEquals($this->refresh($user)->created_at, $this->refresh($user)->copied_created_at);
    }

    public function testGenerated()
    {
        $user = User::first();

        $this->assertEquals(1, $user->posts_count);
        $this->assertEquals(2, $user->posts_count_plus_one);
    }

    public function testAsynchronous()
    {
        $user = User::first();

        $this->assertEquals(0, $user->asynchronous_posts_weight_sum);

        User::processHoard();

        $this->assertEquals(1, $this->refresh($user)->asynchronous_posts_weight_sum);

        $post = new Post;
        $post->user_sequence = $user->sequence;
        $post->visible = true;
        $post->weight = 3;
        $post->save();

        $post = new Post;
        $post->user_sequence = $user->sequence;
        $post->visible = true;
        $post->weight = 5;
        $post->save();

        $this->assertEquals(1, $this->refresh($user)->asynchronous_posts_weight_sum);

        $this->assertEquals(2, DB::table(HoardSchema::$cacheSchema . '.logs')
            ->whereNull('processed_at')
            ->whereNull('canceled_at')
            ->get()->count());

        User::processHoard();

        $this->assertEquals(0, DB::table(HoardSchema::$cacheSchema . '.logs')
            ->whereNull('processed_at')
            ->whereNull('canceled_at')
            ->get()->count());

        $this->assertEquals(9, $this->refresh($user)->asynchronous_posts_weight_sum);

        $post = new Post;
        $post->user_sequence = $user->sequence;
        $post->visible = true;
        $post->weight = 10;
        $post->save();

        $this->assertEquals(1, DB::table(HoardSchema::$cacheSchema . '.logs')
            ->whereNull('processed_at')
            ->whereNull('canceled_at')
            ->get()->count());

        User::processHoard();

        $this->assertEquals(0, DB::table(HoardSchema::$cacheSchema . '.logs')
            ->whereNull('processed_at')
            ->whereNull('canceled_at')
            ->get()->count());

        User::processHoard();

        $this->assertEquals(19, $this->refresh($user)->asynchronous_posts_weight_sum);

        $post->delete();

        $this->assertEquals(2, DB::table(HoardSchema::$cacheSchema . '.logs')
            ->whereNull('processed_at')
            ->whereNull('canceled_at')
            ->get()->count());

        User::processHoard();

        $this->assertEquals(9, $this->refresh($user)->asynchronous_posts_weight_sum);

        $this->assertEquals(0, DB::table(HoardSchema::$cacheSchema . '.logs')
            ->whereNull('processed_at')
            ->whereNull('canceled_at')
            ->get()->count());
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
        $this->assertEquals(1, $this->refresh($this->data['tag'])->taggables_count);

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

    public function testPush()
    {
        $tag = $this->data['tag'];
        $post = $this->data['post'];

        $this->assertEquals('[]', $this->refresh($post)->comments_ids);

        $comment = new Comment();
        $comment->user_sequence = $this->data['user']->sequence;
        $comment->post_id = $this->data['post']->id;
        $comment->save();

        $this->assertEquals(1, $this->refresh($post)->comments_count);
        $this->assertEquals('["1"]', $this->refresh($post)->comments_ids);
        $this->assertEquals('[1]', $this->refresh($post)->comments_numeric_ids);
        $this->assertEquals(1, $this->refresh($this->data['post'])->comments_count);

        $secondComment = new Comment();
        $secondComment->user_sequence = $this->data['user']->sequence;
        $secondComment->post_id = $this->data['post']->id;
        $secondComment->save();

        $this->assertEquals(2, $this->refresh($post)->comments_count);
        $this->assertEquals('["1", "2"]', $this->refresh($post)->comments_ids);
        $this->assertEquals('[1, 2]', $this->refresh($post)->comments_numeric_ids);
        $this->assertEquals(2, $this->refresh($this->data['post'])->comments_count);

        $comment->delete();

        $this->assertEquals(1, $this->refresh($post)->comments_count);
        $this->assertEquals('["2"]', $this->refresh($post)->comments_ids);
        $this->assertEquals('[2]', $this->refresh($post)->comments_numeric_ids);
        $this->assertEquals(1, $this->refresh($this->data['post'])->comments_count);

        $secondComment->delete();

        $this->assertEquals(0, $this->refresh($post)->comments_count);
        $this->assertEquals('[]', $this->refresh($post)->comments_ids);
        $this->assertEquals('[]', $this->refresh($post)->comments_numeric_ids);
        $this->assertEquals(0, $this->refresh($this->data['post'])->comments_count);
    }

    public function testGroup()
    {
        $user = $this->data['user'];

        $mondayPost = new Post();
        $mondayPost->user_sequence = $user->sequence;
        $mondayPost->visible = false;
        $mondayPost->created_at = Carbon::parse('2022-04-25 14:50:00+02');
        $mondayPost->weight = 5;
        $mondayPost->save();

        $tuesdayPost = new Post();
        $tuesdayPost->user_sequence = $user->sequence;
        $tuesdayPost->visible = false;
        $tuesdayPost->created_at = Carbon::parse('2022-04-26 14:50:00+02');
        $tuesdayPost->weight = 3.5;
        $tuesdayPost->save();

        $fridayPost = new Post();
        $fridayPost->user_sequence = $user->sequence;
        $fridayPost->visible = false;
        $fridayPost->created_at = Carbon::parse('2022-04-29 14:50:00+02');
        $fridayPost->weight = 7;
        $fridayPost->save();

        $saturdayPost = new Post();
        $saturdayPost->user_sequence = $user->sequence;
        $saturdayPost->visible = false;
        $saturdayPost->created_at = Carbon::parse('2022-04-30 14:50:00+02');
        $saturdayPost->weight = 8;
        $saturdayPost->save();

        $this->assertEquals([
            '1' => 1,
            '2' => 2,
            '5' => 1,
            '6' => 1,
        ], $this->refresh($user)->grouped_posts_count_by_weekday);
        $this->assertEquals([
            '1' => 5,
            '2' => 4.5,
            '5' => 7,
        ], $this->refresh($user)->grouped_posts_weight_by_workingday);

        $user->refreshHoard();

        $this->assertEquals([
            '1' => 1,
            '2' => 2,
            '5' => 1,
            '6' => 1,
        ], $this->refresh($user)->grouped_posts_count_by_weekday);

        $this->assertEquals([
            '1' => 5,
            '2' => 4.5,
            '5' => 7,
        ], $this->refresh($user)->grouped_posts_weight_by_workingday);

        $tuesdayPost->created_at = $mondayPost->created_at;
        $tuesdayPost->weight = 10;
        $tuesdayPost->save();

        $this->assertEquals([
            '1' => 2,
            '2' => 1,
            '5' => 1,
            '6' => 1,
        ], $this->refresh($user)->grouped_posts_count_by_weekday);

        $this->assertEquals([
            '1' => 15,
            '2' => 1,
            '5' => 7,
        ], $this->refresh($user)->grouped_posts_weight_by_workingday);

        $tuesdayPost->delete();

        $this->assertEquals([
            '1' => 1,
            '2' => 1,
            '5' => 1,
            '6' => 1,
        ], $this->refresh($user)->grouped_posts_count_by_weekday);

        $this->assertEquals([
            '1' => 5,
            '2' => 1,
            '5' => 7,
        ], $this->refresh($user)->grouped_posts_weight_by_workingday);

        $mondayPost->delete();

        $this->assertEquals([
            '1' => 0,
            '2' => 1,
            '5' => 1,
            '6' => 1,
        ], $this->refresh($user)->grouped_posts_count_by_weekday);

        $this->assertEquals([
            '1' => 0,
            '2' => 1,
            '5' => 7,
        ], $this->refresh($user)->grouped_posts_weight_by_workingday);
    }
}
