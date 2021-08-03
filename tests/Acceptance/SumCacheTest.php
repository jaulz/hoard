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

        $this->assertEquals(34, $order->item_total);
        $this->assertEquals(34, $order->item_total_explicit);
    }

    public function testAdditionalSumCache()
    {
        $order = new Order;
        $order->save();

        $this->assertEquals(34, Order::first()->item_total);
        $this->assertEquals(0,  Order::get()[1]->item_total);

        $this->assertEquals(34, Order::first()->item_total_explicit);
        $this->assertEquals(0,  Order::get()[1]->item_total_explicit);

        $this->assertEquals(0, Order::first()->item_total_conditional);
        $this->assertEquals(0,  Order::get()[1]->item_total_conditional);

        $this->assertEquals(0, Order::first()->item_total_complex_conditional);
        $this->assertEquals(0,  Order::get()[1]->item_total_complex_conditional);

        $item = new Item;
        $item->order_id = $this->data['order']->id;
        $item->total = 35;
        $item->billable = true;
        $item->save();

        $this->assertEquals(69, Order::first()->item_total);
        $this->assertEquals(0,  Order::get()[1]->item_total);

        $this->assertEquals(69, Order::first()->item_total_explicit);
        $this->assertEquals(0,  Order::get()[1]->item_total_explicit);

        $this->assertEquals(35, Order::first()->item_total_conditional);
        $this->assertEquals(0,  Order::get()[1]->item_total_conditional);

        $this->assertEquals(35, Order::first()->item_total_complex_conditional);
        $this->assertEquals(0,  Order::get()[1]->item_total_complex_conditional);

        $item->total = 45;
        $item->save();

        $this->assertEquals(79, Order::first()->item_total);
        $this->assertEquals(0,  Order::get()[1]->item_total);

        $this->assertEquals(79, Order::first()->item_total_explicit);
        $this->assertEquals(0,  Order::get()[1]->item_total_explicit);

        $this->assertEquals(45, Order::first()->item_total_conditional);
        $this->assertEquals(0,  Order::get()[1]->item_total_conditional);

        $this->assertEquals(0, Order::first()->item_total_complex_conditional);
        $this->assertEquals(0,  Order::get()[1]->item_total_complex_conditional);
        
        $item->order_id = $order->id;
        $item->save();

        $this->assertEquals(34, Order::first()->item_total);
        $this->assertEquals(45, Order::get()[1]->item_total);

        $this->assertEquals(34, Order::first()->item_total_explicit);
        $this->assertEquals(45, Order::get()[1]->item_total_explicit);

        $this->assertEquals(0, Order::first()->item_total_conditional);
        $this->assertEquals(45,  Order::get()[1]->item_total_conditional);

        $this->assertEquals(0, Order::first()->item_total_complex_conditional);
        $this->assertEquals(0,  Order::get()[1]->item_total_complex_conditional);

        $item->total = 40;
        $item->save();

        $this->assertEquals(34, Order::first()->item_total);
        $this->assertEquals(40, Order::get()[1]->item_total);

        $this->assertEquals(34, Order::first()->item_total_explicit);
        $this->assertEquals(40, Order::get()[1]->item_total_explicit);

        $this->assertEquals(0, Order::first()->item_total_conditional);
        $this->assertEquals(40,  Order::get()[1]->item_total_conditional);

        $this->assertEquals(0, Order::first()->item_total_complex_conditional);
        $this->assertEquals(40,  Order::get()[1]->item_total_complex_conditional);

        $item->delete();

        $this->assertEquals(34, Order::first()->item_total);
        $this->assertEquals(0, Order::get()[1]->item_total);

        $this->assertEquals(34, Order::first()->item_total_explicit);
        $this->assertEquals(0, Order::get()[1]->item_total_explicit);

        $this->assertEquals(0, Order::first()->item_total_conditional);
        $this->assertEquals(0,  Order::get()[1]->item_total_conditional);

        $this->assertEquals(0, Order::first()->item_total_complex_conditional);
        $this->assertEquals(0,  Order::get()[1]->item_total_complex_conditional);

        $item->restore();

        $this->assertEquals(34, Order::first()->item_total);
        $this->assertEquals(40, Order::get()[1]->item_total);

        $this->assertEquals(34, Order::first()->item_total_explicit);
        $this->assertEquals(40, Order::get()[1]->item_total_explicit);

        $this->assertEquals(0, Order::first()->item_total_conditional);
        $this->assertEquals(40,  Order::get()[1]->item_total_conditional);

        $this->assertEquals(0, Order::first()->item_total_complex_conditional);
        $this->assertEquals(40,  Order::get()[1]->item_total_complex_conditional);
    }

    private function setupOrderAndItem()
    {
        $order = new Order;
        $order->save();

        $item = new Item;
        $item->total = 34;
        $item->order_id = $order->id;
        $item->billable = false;
        $item->save();

        return compact('order', 'item');
    }
}
