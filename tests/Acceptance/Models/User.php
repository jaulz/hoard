<?php

namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Jaulz\Hoard\Scopes\HoardScope;
use Jaulz\Hoard\Traits\IsHoardableTrait;
use Tests\Acceptance\Models\Traits\IsRefreshableTrait;

class User extends Model
{
    use IsHoardableTrait;
    use IsRefreshableTrait;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
      'copied_created_at' => 'datetime',
      'asynchronous_copied_created_at' => 'datetime',
    ];

    /**
     * Indicates model primary keys.
     */
    protected $primaryKey = 'sequence';

    public static function boot()
    {
        parent::boot();

        static::addGlobalScope(new HoardScope());
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
