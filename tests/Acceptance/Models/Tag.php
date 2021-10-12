<?php

namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Jaulz\Hoard\Traits\IsHoardableTrait;

class Tag extends Model
{
    use IsHoardableTrait;

    public static function hoard()
    {
        return [
            [
                'function' => 'COUNT',
                'relationName' => 'posts',
                'summaryName' => 'tags_count',
            ],

            [
                'function' => 'COUNT',
                'relationName' => 'posts',
                'summaryName' => 'important_tags_count',
                'wherePivot' => [
                    ['weight', '>', 5]
                ]
            ],
        ];
    }
    
    public function posts()
    {
        return $this->morphedByMany(Post::class, 'taggable')->using(Taggable::class)->withTimestamps();
    }
    
    public function images()
    {
        return $this->morphedByMany(Image::class, 'taggable')->using(Taggable::class)->withTimestamps();
    }
}