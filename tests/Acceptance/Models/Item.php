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
                'foreign_model' => 'Tests\Acceptance\Models\Order',
                'value' => 'total',
                'summary' => 'item_total',
            ],

            [
                'function' => 'SUM',
                'foreign_model' => 'Tests\Acceptance\Models\Order',
                'summary' => 'item_total_explicit',
                'value' => 'total',
                'foreign_key' => 'order_id',
                'key' => 'id',
            ],

            [
                'function' => 'sUm',
                'foreign_model' => 'Tests\Acceptance\Models\Order',
                'summary' => 'item_total_conditional',
                'value' => 'total',
                'foreign_key' => 'order_id',
                'key' => 'id',
                'where' => [
                    'billable' => true 
                ]
            ],
            [
                'function' => 'SUM',
                'foreign_model' => 'Tests\Acceptance\Models\Order',
                'summary' => 'item_total_complex_conditional',
                'value' => 'total',
                'foreign_key' => 'order_id',
                'key' => 'id',
                'where' => [
                    'billable' => true,
                    ['total', '<', 45] 
                ]
            ]
        ];
    }
}
