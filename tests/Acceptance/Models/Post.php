<?php
namespace Tests\Acceptance\Models;

use Jaulz\Eloquence\Behaviours\Cacheable;
use Jaulz\Eloquence\Behaviours\CamelCasing;
use Jaulz\Eloquence\Behaviours\Sluggable;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use CamelCasing;
    use Sluggable;
    use Cacheable;

    public function caches()
    {
        return [
            [
                'function' => 'COUNT',
                'model' => 'Tests\Acceptance\Models\User',
                'summary' => 'postCount',
                'foreignKey' => 'userId',
                'key' => 'id',
            ],

            [
                'function' => 'COUNT',
                'model' => 'Tests\Acceptance\Models\User',
                'summary' => 'postCountExplicit',
                'foreignKey' => 'userId',
                'key' => 'id',
            ],

            [
                'function' => 'COUNT',
                'model' => 'Tests\Acceptance\Models\User',
                'summary' => 'postCountConditional',
                'foreignKey' => 'userId',
                'key' => 'id',
                'where' => [
                    'visible' => true, 
                ]
            ],

            [
                'function' => 'COUNT',
                'model' => 'Tests\Acceptance\Models\User',
                'summary' => 'postCountComplexConditional',
                'foreignKey' => 'userId',
                'key' => 'id',
                'where' => [
                    'visible' => true,
                    ['weight', '>', 5] 
                ]
            ],

            [
                'function' => 'SUM',
                'model' => 'Tests\Acceptance\Models\User',
                'summary' => 'post_comment_sum',
                'value' => 'comment_count',
                'foreignKey' => 'userId',
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
