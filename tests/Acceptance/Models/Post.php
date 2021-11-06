<?php

namespace Tests\Acceptance\Models;

use Jaulz\Hoard\Traits\IsHoardableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use IsHoardableTrait;
    use SoftDeletes;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable')->using(Taggable::class)->withPivot('weight', 'taggable_count', 'taggable_created_at')->withTimestamps();
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }
}
