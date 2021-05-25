<?php
namespace Tests\Acceptance;

use Tests\Acceptance\Models\Item;
use Tests\Acceptance\Models\Order;

class SumCacheTest extends AcceptanceTestCase
{
    private $data = [];

    public function init()
    {
        $this->data = $this->setupOrderAndItem();
    }

    public function testOrderSumCache()
    {
        $order = Order::first();

        $this->assertEquals(34, $order->itemTotal);
        $this->assertEquals(34, $order->itemTotalExplicit);
    }

    public function testAdditionalSumCache()
    {
        $order = new Order;
        $order->save();

        $this->assertEquals(34, Order::first()->itemTotal);
        $this->assertEquals(0,  Order::get()[1]->itemTotal);

        $this->assertEquals(34, Order::first()->itemTotalExplicit);
        $this->assertEquals(0,  Order::get()[1]->itemTotalExplicit);

        $this->assertEquals(0, Order::first()->itemTotalConditional);
        $this->assertEquals(0,  Order::get()[1]->itemTotalConditional);

        $this->assertEquals(0, Order::first()->itemTotalComplexConditional);
        $this->assertEquals(0,  Order::get()[1]->itemTotalComplexConditional);

        $item = new Item;
        $item->orderId = $this->data['order']->id;
        $item->total = 35;
        $item->billable = true;
        $item->save();

        $this->assertEquals(69, Order::first()->itemTotal);
        $this->assertEquals(0,  Order::get()[1]->itemTotal);

        $this->assertEquals(69, Order::first()->itemTotalExplicit);
        $this->assertEquals(0,  Order::get()[1]->itemTotalExplicit);

        $this->assertEquals(35, Order::first()->itemTotalConditional);
        $this->assertEquals(0,  Order::get()[1]->itemTotalConditional);

        $this->assertEquals(35, Order::first()->itemTotalComplexConditional);
        $this->assertEquals(0,  Order::get()[1]->itemTotalComplexConditional);

        $item->total = 45;
        $item->save();

        $this->assertEquals(79, Order::first()->itemTotal);
        $this->assertEquals(0,  Order::get()[1]->itemTotal);

        $this->assertEquals(79, Order::first()->itemTotalExplicit);
        $this->assertEquals(0,  Order::get()[1]->itemTotalExplicit);

        $this->assertEquals(45, Order::first()->itemTotalConditional);
        $this->assertEquals(0,  Order::get()[1]->itemTotalConditional);

        $this->assertEquals(0, Order::first()->itemTotalComplexConditional);
        $this->assertEquals(0,  Order::get()[1]->itemTotalComplexConditional);
        
        $item->orderId = $order->id;
        $item->save();

        $this->assertEquals(34, Order::first()->itemTotal);
        $this->assertEquals(45, Order::get()[1]->itemTotal);

        $this->assertEquals(34, Order::first()->itemTotalExplicit);
        $this->assertEquals(45, Order::get()[1]->itemTotalExplicit);

        $this->assertEquals(0, Order::first()->itemTotalConditional);
        $this->assertEquals(45,  Order::get()[1]->itemTotalConditional);

        $this->assertEquals(0, Order::first()->itemTotalComplexConditional);
        $this->assertEquals(0,  Order::get()[1]->itemTotalComplexConditional);

        $item->total = 40;
        $item->save();

        $this->assertEquals(34, Order::first()->itemTotal);
        $this->assertEquals(40, Order::get()[1]->itemTotal);

        $this->assertEquals(34, Order::first()->itemTotalExplicit);
        $this->assertEquals(40, Order::get()[1]->itemTotalExplicit);

        $this->assertEquals(0, Order::first()->itemTotalConditional);
        $this->assertEquals(40,  Order::get()[1]->itemTotalConditional);

        $this->assertEquals(0, Order::first()->itemTotalComplexConditional);
        $this->assertEquals(40,  Order::get()[1]->itemTotalComplexConditional);
    }

    private function setupOrderAndItem()
    {
        $order = new Order;
        $order->save();

        $item = new Item;
        $item->total = 34;
        $item->orderId = $order->id;
        $item->billable = false;
        $item->save();

        return compact('order', 'item');
    }
}
