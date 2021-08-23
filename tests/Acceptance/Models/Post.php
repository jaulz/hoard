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
                'foreign_model' => Tag::class,
                'key' => 'id',
                'summary' => 'cached_taggables_count',
                'foreign_key' =>[
                  'id',
                  function ($id) {
                    return Taggable::where('taggable_id', $id)->pluck('tag_id');
                  },
                  function ($query, $id) {
                    $postIds = Taggable::where('taggable_id', $id)->pluck('taggable_id');
                    $query->whereIn('id', $postIds);
                  },
                ],
                'through' => Taggable::class,
                'where' => [],
            ],
        ];
    }

    public function user()
    {
        return $this->belongsTo(Post::class);
    }
    
    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable')->using(Taggable::class);
    }
}
