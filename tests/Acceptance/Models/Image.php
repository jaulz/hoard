<?php

namespace Tests\Acceptance\Models;

use Jaulz\Hoard\Traits\IsHoardableTrait;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    public function imageable()
    {
        return $this->morphTo();
    }
    
    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable')->using(Taggable::class);
    }
}
