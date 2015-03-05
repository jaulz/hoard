<?php
namespace Tests\Behaviours\CountCache;

use Eloquence\Behaviours\CountCache\CountCacheManager;
use Illuminate\Support\Facades\DB;
use Tests\Stubs\CountCache\Comment;
use Tests\Stubs\CountCache\Post;
use Tests\Stubs\RealModelStub;
use Tests\TestCase;

class CountCacheManagerTest extends TestCase
{
    private $manager;

    public function init()
    {
        $this->manager = new CountCacheManager;
    }

    public function testGetTable()
    {
        $this->assertEquals('real_model_stubs', $this->manager->getTable(new RealModelStub));
        $this->assertEquals('real_model_stubs', $this->manager->getTable('Tests\Stubs\RealModelStub'));
    }

    public function testIncrementRelatedModel()
    {
        $post = new Post;
        $post->user_id = 2;

        $params = [
            '+',
            'id',
            2
        ];

        DB::shouldReceive('update')->with('UPDATE `users` SET `posts_count` = `posts_count` ? 1 WHERE ? = ?', $params);

        $this->manager->increment($post);
    }

    public function testDecrementRelatedModel()
    {
        $comment = new Comment;
        $comment->post_id = 7;
        $comment->user_id = 1;

        $firstOperationParams = [
            '-',
            'id',
            7
        ];

        $secondOperationParams = [
            '-',
            'id',
            1
        ];

        DB::shouldReceive('update')->with('UPDATE `posts` SET `num_comments` = `num_comments` ? 1 WHERE ? = ?', $firstOperationParams)->once();
        DB::shouldReceive('update')->with('UPDATE `users` SET `comment_count` = `comment_count` ? 1 WHERE ? = ?', $secondOperationParams)->once();

        $this->manager->decrement($comment);
    }

    public function testUpdateCache()
    {
        $comment = new Comment;
        $comment->post_id = 1;
        $comment->syncOriginal();
        $comment->post_id = 2;

        $this->manager->setOriginal($comment->getOriginal());

        $firstOperationParams = [
            '-',
            'id',
            1
        ];

        $secondOperationParams = [
            '+',
            'id',
            2
        ];

        DB::shouldReceive('update')->with('UPDATE `posts` SET `num_comments` = `num_comments` ? 1 WHERE ? = ?', $firstOperationParams)->once();
        DB::shouldReceive('update')->with('UPDATE `posts` SET `num_comments` = `num_comments` ? 1 WHERE ? = ?', $secondOperationParams)->once();

        $this->manager->updateCache($comment);
    }
}