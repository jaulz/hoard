<?php

namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Jaulz\Hoard\Traits\IsCacheableTrait;

class Tag extends Model
{
    use IsCacheableTrait;
    
    public function posts()
    {
        return $this->morphedByMany(Post::class, 'taggable')->using(Taggable::class);
    }
    
    public function images()
    {
        return $this->morphedByMany(Image::class, 'taggable')->using(Taggable::class);
    }
}