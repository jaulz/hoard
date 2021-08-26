<?php
namespace Tests\Acceptance\Models;

use Jaulz\Eloquence\Traits\IsCacheableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use IsCacheableTrait;
    use SoftDeletes;

    public static function caches()
    {
        return [
            [
                'function' => 'count',
                'foreignModelName' => 'Tests\Acceptance\Models\Post',
                'context' => function ($model) {
                    return [
                        'user_id' => $model->user_id
                    ];
                }
            ],

            [
                'function' => 'COUNT',
                'foreignModelName' => 'Tests\Acceptance\Models\User',
            ],

            [
                'function' => 'MAX',
                'foreignModelName' => 'Tests\Acceptance\Models\Post',
                'summaryName' => 'last_commented_at',
                'valueName' => 'created_at'
            ],

            [
                'function' => 'MIN',
                'foreignModelName' => 'Tests\Acceptance\Models\Post',
                'summaryName' => 'first_commented_at',
                'valueName' => 'created_at'
            ],
        ];
    }
}
