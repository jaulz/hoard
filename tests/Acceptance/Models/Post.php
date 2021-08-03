<?php
namespace Tests\Acceptance\Models;

use Jaulz\Eloquence\Behaviours\Cacheable;
use Jaulz\Eloquence\Behaviours\Sluggable;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{    use Sluggable;
    use Cacheable;

    public function caches()
    {
        return [
            [
                'function' => 'COUNT',
                'model' => 'Tests\Acceptance\Models\User',
                'summary' => 'posts_count',
                'foreignKey' => 'user_id',
                'key' => 'id',
            ],

            [
                'function' => 'COUNT',
                'model' => 'Tests\Acceptance\Models\User',
                'summary' => 'posts_count_explicit',
                'foreignKey' => 'user_id',
                'key' => 'id',
            ],

            [
                'function' => 'COUNT',
                'model' => 'Tests\Acceptance\Models\User',
                'summary' => 'posts_count_conditional',
                'foreignKey' => 'user_id',
                'key' => 'id',
                'where' => [
                    'visible' => true, 
                ]
            ],

            [
                'function' => 'COUNT',
                'model' => 'Tests\Acceptance\Models\User',
                'summary' => 'posts_count_complex_conditional',
                'foreignKey' => 'user_id',
                'key' => 'id',
                'where' => [
                    'visible' => true,
                    ['weight', '>', 5] 
                ]
            ],

            [
                'function' => 'SUM',
                'model' => 'Tests\Acceptance\Models\User',
                'summary' => 'post_comments_sum',
                'value' => 'comments_count',
                'foreignKey' => 'user_id',
                'key' => 'id',
            ],
        ];
    }

    public function slugStrategy()
    {
        return 'id';
    }

    public function user()
    {
        return $this->belongsTo(Post::class);
    }
}
