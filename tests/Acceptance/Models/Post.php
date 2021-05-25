<?php
namespace Tests\Acceptance\Models;

use Eloquence\Behaviours\CountCache\Countable;
use Eloquence\Behaviours\CamelCasing;
use Eloquence\Behaviours\Sluggable;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use CamelCasing;
    use Sluggable;
    use Countable;

    public function countCaches()
    {
        return [
            'postCount' => ['Tests\Acceptance\Models\User', 'userId', 'id'],
            [
                'model' => 'Tests\Acceptance\Models\User',
                'field' => 'postCountExplicit',
                'foreignKey' => 'userId',
                'key' => 'id',
            ],
            [
                'model' => 'Tests\Acceptance\Models\User',
                'field' => 'postCountConditional',
                'foreignKey' => 'userId',
                'key' => 'id',
                'where' => [
                    'visible' => true, 
                ]
            ],
            [
                'model' => 'Tests\Acceptance\Models\User',
                'field' => 'postCountComplexConditional',
                'foreignKey' => 'userId',
                'key' => 'id',
                'where' => [
                    'visible' => true,
                    ['weight', '>', 5] 
                ]
            ]
        ];
    }

    public function slugStrategy()
    {
        return 'id';
    }
}
