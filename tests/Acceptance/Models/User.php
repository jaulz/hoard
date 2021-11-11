<?php
namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Jaulz\Hoard\Traits\IsHoardableTrait;

class User extends Model {
    use IsHoardableTrait;

    /**
     * Indicates model primary keys.
     */
    protected $primaryKey = 'sequence';

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
    
    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }
}
