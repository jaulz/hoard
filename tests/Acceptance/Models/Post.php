<?php

namespace Tests\Acceptance\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Jaulz\Hoard\Traits\IsHoardableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Jaulz\Hoard\Scopes\HoardScope;

class Post extends Model
{
    use IsHoardableTrait;
    use SoftDeletes;

    public static function boot()
    {
        parent::boot();

        static::addGlobalScope(new HoardScope(function (Builder $builder) {
            $builder->select('*');
        }));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
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
