<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Product;
use App\Models\Hold;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Services\PaymentEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WebhookBeforeOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_before_order_creation_gets_applied_after_order_created()
    {
        // create product and hold but do NOT create order yet
        $product = Product::create(['name'=>'Item','price'=>1000,'stock'=>10,'reserved_count'=>2]);
        $hold = Hold::create(['product_id'=>$product->id,'qty'=>2,'status'=>'active','expires_at'=>now()->addMinutes(5)]);

        $payload = [
            'idempotency_key' => 'evt_before_1',
            'external_payment_id' => 'pay_early',
            'hold_id' => $hold->id,
            'result' => 'success'
        ];

        // 1) Webhook arrives before order: it should create PaymentEvent and save it
        $this->postJson('/api/payments/webhook', $payload)->assertStatus(200)->assertJson(['ok' => true]);

        $this->assertDatabaseHas('payment_events', ['idempotency_key' => 'evt_before_1']);

        // Simulate later: create order from hold (this should process any pre-recorded payment events)
        $orderResponse = $this->postJson('/api/orders', ['hold_id' => $hold->id])->assertStatus(201);
        $orderId = $orderResponse->json('order_id');

        // After order creation, processor should have applied the earlier event and mark order paid
        $order = Order::find($orderId);
        $product->refresh();
        $this->assertEquals('paid', $order->status);
        $this->assertEquals(8, $product->stock); // 10 - 2
        $this->assertEquals(0, $product->reserved_count);
    }
}
