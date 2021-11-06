<?php
namespace Tests\Acceptance\Models;

use Jaulz\Hoard\Traits\IsHoardableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use IsHoardableTrait;
    use SoftDeletes;
}
