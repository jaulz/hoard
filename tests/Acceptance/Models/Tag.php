<?php

namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Jaulz\Eloquence\Traits\IsCacheableTrait;

class Tag extends Model
{
    use IsCacheableTrait;
    
    public function posts()
    {
        return $this->morphedByMany(Post::class, 'taggable')->using(Taggable::class);
    }
}