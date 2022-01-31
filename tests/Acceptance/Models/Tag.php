<?php

namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Jaulz\Hoard\Scopes\HoardScope;
use Jaulz\Hoard\Traits\IsHoardableTrait;
use Tests\Acceptance\Models\Traits\IsRefreshableTrait;

class Tag extends Model
{
    use IsHoardableTrait;
    use IsRefreshableTrait;

    public static function boot()
    {
        parent::boot();

        static::addGlobalScope(new HoardScope());
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