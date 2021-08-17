<?php
namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Jaulz\Eloquence\Behaviours\Cacheable;

class User extends Model {
    use Cacheable;

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function slugStrategy()
    {
        return ['first_name', 'last_name'];
    }
}
