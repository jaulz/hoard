<?php
namespace Tests\Unit\Stubs\SumCache;

use Illuminate\Database\Eloquent\Model;
use Jaulz\Eloquence\Traits\IsCacheableTrait;

class Item extends Model
{
    use IsCacheableTrait;

    public function caches()
    {
        return [
            [
                'function' => 'sum',
                'model' => 'Tests\Unit\Stubs\SumCache\Order',
            ],

            [
                'function' => 'sum',
                'model' => 'Tests\Unit\Stubs\SumCache\Order',
                'summary' => 'item_total_explicit',
                'field' => 'total',
                'foreignKey' => 'itemId',
                'key' => 'id',
            ]
        ];
    }
}
