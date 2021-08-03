<?php
namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    public function items()
    {
        return $this->hasMany('Tests\Acceptance\Models\Item');
    }
}
