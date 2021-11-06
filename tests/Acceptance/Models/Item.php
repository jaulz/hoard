<?php

namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Jaulz\Hoard\Traits\IsHoardableTrait;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use IsHoardableTrait;
    use SoftDeletes;
}
