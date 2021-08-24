<?php

namespace Tests\Acceptance\Models;

use Jaulz\Eloquence\Traits\IsCacheableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use IsCacheableTrait;
    use SoftDeletes;

    public static function caches()
    {
        return [
            [
                'function' => 'COUNT',
                'relation' => 'user',
                'summary' => 'posts_count',
            ],

            [
                'function' => 'COUNT',
                'relation' => 'user',
                'summary' => 'posts_count_explicit',
            ],

            [
                'function' => 'COUNT',
                'relation' => 'user',
                'summary' => 'posts_count_conditional',
                'foreign_key' => 'user_id',
                'key' => 'id',
                'where' => [
                    'visible' => true,
                ]
            ],

            [
                'function' => 'COUNT',
                'relation' => 'user',
                'summary' => 'posts_count_complex_conditional',
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
                'foreign_key' =>[
                  'user_id',
                  function ($userId) {
                    return $userId;
                  },
                  function ($query, $userId) {
                    $query->where('user_id', '=', $userId);
                  },
                ],
                'key' => 'id',
            ],

            [
                'function' => 'COUNT',
                'relation' => 'tags',
                'summary' => 'taggables_count',
            ],
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable')->using(Taggable::class);
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }
}
