<?php
namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Jaulz\Hoard\Traits\IsCacheableTrait;

class User extends Model {
    use IsCacheableTrait;

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
    
    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }
}
