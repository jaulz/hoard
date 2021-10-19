<?php

namespace Tests\Acceptance;

use Illuminate\Support\Facades\DB;
use Tests\Acceptance\Models\Comment;
use Tests\Acceptance\Models\Post;

class NativeAcceptanceTest extends AcceptanceTestCase
{
    protected $native = true;

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'test');
        $app['config']->set('database.connections.test', array(
            'driver'   => 'pgsql',
            'database' => 'hoard'
        ));
    }

    public function testJsonbAgg()
    {
        $tag = $this->data['tag'];
        $post = $this->data['post'];

        $this->assertEquals('[]', $post->refresh()->comments_ids);

        $comment = new Comment();
        $comment->user_id = $this->data['user']->id;
        $comment->post_id = $this->data['post']->id;
        $comment->save();

        $this->assertEquals(1, $post->refresh()->comments_count);
        $this->assertEquals('["1"]', $post->refresh()->comments_ids);
        $this->assertEquals('[1]', $post->refresh()->comments_numeric_ids);
        $this->assertEquals(1, $this->data['post']->refresh()->comments_count);

        $secondComment = new Comment();
        $secondComment->user_id = $this->data['user']->id;
        $secondComment->post_id = $this->data['post']->id;
        $secondComment->save();

        $this->assertEquals(2, $post->refresh()->comments_count);
        $this->assertEquals('["1", "2"]', $post->refresh()->comments_ids);
        $this->assertEquals('[1, 2]', $post->refresh()->comments_numeric_ids);
        $this->assertEquals(2, $this->data['post']->refresh()->comments_count);

        $comment->delete();

        $this->assertEquals(1, $post->refresh()->comments_count);
        $this->assertEquals('["2"]', $post->refresh()->comments_ids);
        $this->assertEquals('[2]', $post->refresh()->comments_numeric_ids);
        $this->assertEquals(1, $this->data['post']->refresh()->comments_count);

        $secondComment->delete();

        $this->assertEquals(0, $post->refresh()->comments_count);
        $this->assertEquals('[]', $post->refresh()->comments_ids);
        $this->assertEquals('[]', $post->refresh()->comments_numeric_ids);
        $this->assertEquals(0, $this->data['post']->refresh()->comments_count);
    }
}
