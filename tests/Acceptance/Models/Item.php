<?php
namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Jaulz\Eloquence\Behaviours\Cacheable;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{    use Cacheable;
    use SoftDeletes;

    public function caches()
    {
        return [
            [
                'function' => 'sum',
                'model' => 'Tests\Acceptance\Models\Order',
                'value' => 'total',
                'summary' => 'item_total',
            ],

            [
                'function' => 'SUM',
                'model' => 'Tests\Acceptance\Models\Order',
                'summary' => 'item_total_explicit',
                'value' => 'total',
                'foreignKey' => 'order_id',
                'key' => 'id',
            ],

            [
                'function' => 'sUm',
                'model' => 'Tests\Acceptance\Models\Order',
                'summary' => 'item_total_conditional',
                'value' => 'total',
                'foreignKey' => 'order_id',
                'key' => 'id',
                'where' => [
                    'billable' => true 
                ]
            ],
            [
                'function' => 'SUM',
                'model' => 'Tests\Acceptance\Models\Order',
                'summary' => 'item_total_complex_conditional',
                'value' => 'total',
                'foreignKey' => 'order_id',
                'key' => 'id',
                'where' => [
                    'billable' => true,
                    ['total', '<', 45] 
                ]
            ]
        ];
    }
}
