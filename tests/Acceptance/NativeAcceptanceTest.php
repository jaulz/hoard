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
}
