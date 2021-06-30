<?php
namespace Tests\Unit\Stubs\CountCache;

use Illuminate\Database\Eloquent\Model;
use Jaulz\Eloquence\Behaviours\Cacheable;

class Comment extends Model
{
    use Cacheable;

    public function caches()
    {
        return [
            [
                'function' => 'COUNT',
                'model' => 'Tests\Unit\Stubs\CountCache\Post',
                'summary' => 'num_comments',
            ],
            [
                'function' => 'count',
                'model' => 'Tests\Unit\Stubs\CountCache\User',
            ],
            [
                'function' => 'MAX',
                'model' => 'Tests\Unit\Stubs\CountCache\Post',
                'summary' => 'last_commented',
                'field' => 'created_at'
            ],
        ];
    }
}
