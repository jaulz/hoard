<?php
namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Jaulz\Eloquence\Behaviours\Cacheable;

class Order extends Model
{
    use Cacheable;
    
    public function items()
    {
        return $this->hasMany('Tests\Acceptance\Models\Item');
    }
}
