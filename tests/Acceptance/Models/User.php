<?php
namespace Tests\Acceptance\Models;

use Jaulz\Eloquence\Behaviours\Sluggable;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{    use Sluggable;

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function slugStrategy()
    {
        return ['first_name', 'last_name'];
    }
}
