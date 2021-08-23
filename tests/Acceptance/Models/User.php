<?php
namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Jaulz\Eloquence\Traits\IsCacheableTrait;

class User extends Model {
    use IsCacheableTrait;

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function slugStrategy()
    {
        return ['first_name', 'last_name'];
    }
}
