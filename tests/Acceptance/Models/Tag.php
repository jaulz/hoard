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
        ];
    }
    
    public function posts()
    {
        return $this->morphedByMany(Post::class, 'taggable')->using(Taggable::class);
    }
    
    public function images()
    {
        return $this->morphedByMany(Image::class, 'taggable')->using(Taggable::class);
    }
}