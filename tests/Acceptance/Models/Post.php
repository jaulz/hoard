<?php

namespace Tests\Acceptance\Models;

use Jaulz\Hoard\Traits\IsHoardableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use IsHoardableTrait;
    use SoftDeletes;

    public static function hoard()
    {
        return [
            [
                'function' => 'COUNT',
                'relationName' => 'user',
                'summaryName' => 'posts_count',
            ],

            [
                'function' => 'COUNT',
                'relationName' => 'user',
                'summaryName' => 'posts_count_explicit',
            ],

            [
                'function' => 'COUNT',
                'relationName' => 'user',
                'summaryName' => 'posts_count_conditional',
                'foreignKeyName' => 'user_id',
                'key' => 'id',
                'where' => [
                    'visible' => true,
                ]
            ],

            [
                'function' => 'COUNT',
                'relationName' => 'user',
                'summaryName' => 'posts_count_complex_conditional',
                'where' => [
                    'visible' => true,
                    ['weight', '>', 5]
                ]
            ],

            /*[
                'function' => 'SUM',
                'foreignModelName' => 'Tests\Acceptance\Models\User',
                'summaryName' => 'post_comments_sum',
                'valueName' => 'comments_count',
                'foreignKeyName' =>[
                  'user_id',
                  function ($userId) {
                    return $userId;
                  },
                  function ($query, $userId) {
                    $query->where('user_id', '=', $userId);
                  },
                ],
                'key' => 'id',
            ],*/

            [
                'function' => 'COUNT',
                'relationName' => 'tags',
                'summaryName' => 'taggables_count',
            ],

            [
                'function' => 'MAX',
                'relationName' => 'tags',
                'summaryName' => 'last_created_at',
                'valueName' => 'created_at',
            ],

            [
                'function' => 'MIN',
                'relationName' => 'tags',
                'summaryName' => 'first_created_at',
                'valueName' => 'created_at',
            ],
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable')->using(Taggable::class)->withPivot('weight');
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }
}
