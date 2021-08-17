<?php
namespace Tests\Unit\Stubs\CountCache;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    public function slugStrategy()
    {
        return ['id'];
    }
}
