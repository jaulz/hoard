<?php

namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Jaulz\Hoard\Traits\IsCacheableTrait;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use IsCacheableTrait;
    use SoftDeletes;

    public static function hoard()
    {
        return [
            [
                'function' => 'sum',
                'foreignModelName' => 'Tests\Acceptance\Models\Order',
                'valueName' => 'total',
                'summaryName' => 'item_total',
            ],

            [
                'function' => 'SUM',
                'foreignModelName' => 'Tests\Acceptance\Models\Order',
                'summaryName' => 'item_total_explicit',
                'valueName' => 'total',
                'foreignKeyName' => 'order_id',
                'key' => 'id',
            ],

            [
                'function' => 'sUm',
                'foreignModelName' => 'Tests\Acceptance\Models\Order',
                'summaryName' => 'item_total_conditional',
                'valueName' => 'total',
                'foreignKeyName' => 'order_id',
                'key' => 'id',
                'where' => [
                    'billable' => true
                ]
            ],
            [
                'function' => 'SUM',
                'foreignModelName' => 'Tests\Acceptance\Models\Order',
                'summaryName' => 'item_total_complex_conditional',
                'valueName' => 'total',
                'foreignKeyName' => 'order_id',
                'key' => 'id',
                'where' => [
                    'billable' => true,
                    ['total', '<', 45]
                ]
            ]
        ];
    }
}
