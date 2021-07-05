<?php
namespace Tests\Acceptance\Models;

use Jaulz\Eloquence\Behaviours\CamelCasing;
use Illuminate\Database\Eloquent\Model;
use Jaulz\Eloquence\Behaviours\Cacheable;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use CamelCasing;
    use Cacheable;
    use SoftDeletes;

    public function caches()
    {
        return [
            [
                'function' => 'sum',
                'model' => 'Tests\Acceptance\Models\Order',
                'value' => 'total',
                'summary' => 'itemTotal',
            ],

            [
                'function' => 'SUM',
                'model' => 'Tests\Acceptance\Models\Order',
                'summary' => 'itemTotalExplicit',
                'value' => 'total',
                'foreignKey' => 'orderId',
                'key' => 'id',
            ],

            [
                'function' => 'sUm',
                'model' => 'Tests\Acceptance\Models\Order',
                'summary' => 'itemTotalConditional',
                'value' => 'total',
                'foreignKey' => 'orderId',
                'key' => 'id',
                'where' => [
                    'billable' => true 
                ]
            ],
            [
                'function' => 'SUM',
                'model' => 'Tests\Acceptance\Models\Order',
                'summary' => 'itemTotalComplexConditional',
                'value' => 'total',
                'foreignKey' => 'orderId',
                'key' => 'id',
                'where' => [
                    'billable' => true,
                    ['total', '<', 45] 
                ]
            ]
        ];
    }
}
