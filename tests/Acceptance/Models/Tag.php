<?php

namespace Tests\Acceptance\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Jaulz\Hoard\Scopes\HoardScope;
use Jaulz\Hoard\Traits\IsHoardableTrait;

class Tag extends Model
{
    use IsHoardableTrait;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
      'first_created_at' => 'datetime',
      'last_created_at' => 'datetime',
    ];

    public static function boot()
    {
        parent::boot();

        static::addGlobalScope(new HoardScope(function (Builder $builder) {
            $builder->select('*');
        }));
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