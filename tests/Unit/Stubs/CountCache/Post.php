<?php
namespace Tests\Unit\Stubs\CountCache;

use Jaulz\Eloquence\Behaviours\CountCache\Countable;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use Countable;

    public function caches()
    {
        return [
            [
                'function' => 'COUNT',
                'model' => 'Tests\Unit\Stubs\CountCache\User',
                'summary' => 'posts_count',
                'foreignKey' => 'user_id',
                'key' => 'id'
            ],
            [
                'function' => 'count',
                'model' => 'Tests\Unit\Stubs\CountCache\User',
                'summary' => 'posts_count_explicit',
                'foreignKey' => 'user_id',
                'key' => 'id'
            ]
        ];
    }
}
