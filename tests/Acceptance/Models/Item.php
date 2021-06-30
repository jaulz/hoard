<?php
namespace Tests\Acceptance\Models;

use Jaulz\Eloquence\Behaviours\CamelCasing;
use Illuminate\Database\Eloquent\Model;
use Jaulz\Eloquence\Behaviours\Cacheable;

class Item extends Model
{
    use CamelCasing;
    use Cacheable;

    public function caches()
    {
        return [
            [
                'function' => 'sum',
                'model' => 'Tests\Acceptance\Models\Order',
                'field' => 'total',
                'summary' => 'itemTotal',
            ],

            [
                'function' => 'SUM',
                'model' => 'Tests\Acceptance\Models\Order',
                'summary' => 'itemTotalExplicit',
                'field' => 'total',
                'foreignKey' => 'orderId',
                'key' => 'id',
            ],

            [
                'function' => 'sUm',
                'model' => 'Tests\Acceptance\Models\Order',
                'summary' => 'itemTotalConditional',
                'field' => 'total',
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
                'field' => 'total',
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
