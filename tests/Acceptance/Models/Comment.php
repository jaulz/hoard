<?php
namespace Tests\Acceptance\Models;

use Jaulz\Hoard\Traits\IsHoardableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use IsHoardableTrait;
    use SoftDeletes;

    public static function hoard()
    {
        return [
            [
                'function' => 'count',
                'foreignModelName' => 'Tests\Acceptance\Models\Post',
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
