<?php

namespace Tests\Acceptance\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Jaulz\Hoard\Scopes\HoardScope;
use Jaulz\Hoard\Traits\IsHoardableTrait;

class User extends Model
{
    use IsHoardableTrait;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
      'copied_created_at' => 'datetime',
      'asynchronous_copied_created_at' => 'datetime',
      'grouped_posts_count_by_weekday' => 'json',
      'grouped_posts_weight_by_workingday' => 'json'
    ];

    /**
     * Indicates model primary keys.
     */
    protected $primaryKey = 'sequence';

    public static function boot()
    {
        parent::boot();

        static::addGlobalScope(new HoardScope(function (Builder $builder) {
            $builder->select('*');
        }));
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }
}
