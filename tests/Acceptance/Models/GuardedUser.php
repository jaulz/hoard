<?php
namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;

class GuardedUser extends Model
{
    protected $table = 'users';

    /**
     * The attributes that are protected from mass assignment.
     *
     * @var array
     */
    protected $guarded = ['id'];
}
