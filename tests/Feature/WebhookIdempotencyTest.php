<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Product;
use App\Models\Hold;
use App\Models\Order;
use App\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_idempotency_same_key_repeated()
    {
        // seed product, hold and order
        $product = Product::create(['name'=>'Item','price'=>1000,'stock'=>10,'reserved_count'=>2]);
        $hold = Hold::create(['product_id'=>$product->id,'qty'=>2,'status'=>'used','expires_at'=>now()->addMinutes(5)]);
        $order = Order::create(['hold_id'=>$hold->id,'qty'=>2,'status'=>'pending']);

        $payload = [
            'idempotency_key' => 'evt_123',
            'external_payment_id' => 'pay_999',
            'order_id' => $order->id,
            'result' => 'success'
        ];

        // first call: create event and process
        $this->postJson('/api/payments/webhook', $payload, ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJson(['ok' => true]);

        // Validate order paid & stock decreased once
        $order->refresh();
        $product->refresh();
        $this->assertEquals('paid', $order->status);
        $this->assertEquals(8, $product->stock); // 10 - 2
        $this->assertEquals(0, $product->reserved_count);

        // Second call with same idempotency key
        $this->postJson('/api/payments/webhook', $payload, ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJson(['ok' => true]);

        // Ensure nothing changed (idempotent)
        $order->refresh();
        $product->refresh();
        $this->assertEquals('paid', $order->status);
        $this->assertEquals(8, $product->stock);
        $this->assertEquals(0, $product->reserved_count);

        // exactly one PaymentEvent row exists
        $this->assertEquals(1, PaymentEvent::where('idempotency_key', 'evt_123')->count());
    }
}
