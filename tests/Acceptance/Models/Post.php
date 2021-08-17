<?php
namespace Tests\Acceptance\Models;

use Jaulz\Eloquence\Behaviours\Cacheable;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{  
    use Cacheable;

    public function caches()
    {
        return [
            [
                'function' => 'COUNT',
                'foreign_model' => 'Tests\Acceptance\Models\User',
                'summary' => 'posts_count',
                'foreign_key' => 'user_id',
                'key' => 'id',
            ],

            [
                'function' => 'COUNT',
                'foreign_model' => 'Tests\Acceptance\Models\User',
                'summary' => 'posts_count_explicit',
                'foreign_key' => 'user_id',
                'key' => 'id',
            ],

            [
                'function' => 'COUNT',
                'foreign_model' => 'Tests\Acceptance\Models\User',
                'summary' => 'posts_count_conditional',
                'foreign_key' => 'user_id',
                'key' => 'id',
                'where' => [
                    'visible' => true, 
                ]
            ],

            [
                'function' => 'COUNT',
                'foreign_model' => 'Tests\Acceptance\Models\User',
                'summary' => 'posts_count_complex_conditional',
                'foreign_key' => 'user_id',
                'key' => 'id',
                'where' => [
                    'visible' => true,
                    ['weight', '>', 5] 
                ]
            ],

            [
                'function' => 'SUM',
                'foreign_model' => 'Tests\Acceptance\Models\User',
                'summary' => 'post_comments_sum',
                'value' => 'comments_count',
                'foreign_key' => 'user_id',
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
