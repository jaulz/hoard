<?php
namespace Tests\Acceptance\Models;

use Jaulz\Eloquence\Behaviours\CamelCasing;
use Jaulz\Eloquence\Behaviours\Sluggable;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use CamelCasing;
    use Sluggable;

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function slugStrategy()
    {
        return ['firstName', 'lastName'];
    }
}
