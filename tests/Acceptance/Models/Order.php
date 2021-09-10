<?php
namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Jaulz\Hoard\Traits\IsHoardableTrait;

class Order extends Model
{
    use IsHoardableTrait;
    
    public function items()
    {
        return $this->hasMany('Tests\Acceptance\Models\Item');
    }
}
