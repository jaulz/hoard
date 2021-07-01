<?php
namespace Tests\Acceptance\Models;

use Jaulz\Eloquence\Behaviours\Cacheable;
use Jaulz\Eloquence\Behaviours\CamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use CamelCasing;
    use Cacheable;
    use SoftDeletes;

    public function caches()
    {
        return [
            [
                'function' => 'count',
                'model' => 'Tests\Acceptance\Models\Post',
                'propagate' => ['user_id']
            ],

            [
                'function' => 'COUNT',
                'model' => 'Tests\Acceptance\Models\User',
            ],

            [
                'function' => 'MAX',
                'model' => 'Tests\Acceptance\Models\Post',
                'summary' => 'last_commented_at',
                'field' => 'created_at'
            ],

            [
                'function' => 'MIN',
                'model' => 'Tests\Acceptance\Models\Post',
                'summary' => 'first_commented_at',
                'field' => 'created_at'
            ],
        ];
    }
}
