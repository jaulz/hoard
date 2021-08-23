<?php
namespace Tests\Acceptance\Models;

use Jaulz\Eloquence\Traits\IsCacheableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use IsCacheableTrait;
    use SoftDeletes;

    public function caches()
    {
        return [
            [
                'function' => 'count',
                'foreign_model' => 'Tests\Acceptance\Models\Post',
                'context' => function ($model) {
                    return [
                        'user_id' => $model->user_id
                    ];
                }
            ],

            [
                'function' => 'COUNT',
                'foreign_model' => 'Tests\Acceptance\Models\User',
            ],

            [
                'function' => 'MAX',
                'foreign_model' => 'Tests\Acceptance\Models\Post',
                'summary' => 'last_commented_at',
                'value' => 'created_at'
            ],

            [
                'function' => 'MIN',
                'foreign_model' => 'Tests\Acceptance\Models\Post',
                'summary' => 'first_commented_at',
                'value' => 'created_at'
            ],
        ];
    }
}
