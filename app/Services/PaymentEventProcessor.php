<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Order;
use App\Models\PaymentEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PaymentEventProcessor
{
    /**
     * Idempotent Payment Event Handler
     */
    public function processEvent(PaymentEvent $event): void
    {
        if ($event->processed_at) {
            return;
        }

        $payload = (array) ($event->payload ?? []);
        $orderId = $payload['order_id'] ?? null;
        $holdId = $payload['hold_id'] ?? null;
        $result = $payload['result'] ?? $event->result ?? 'unknown';

        DbRetry::run(function () use ($event, $orderId, $holdId, $result) {
            $order = null;

            if ($orderId) {
                $order = Order::lockForUpdate()->find($orderId);
            }

            if (!$order && $holdId) {
                $hold = Hold::lockForUpdate()->find($holdId);
                if ($hold) {
                    $order = Order::where('hold_id', $hold->id)->lockForUpdate()->first();
                }
            }

            if (!$order) {
                // Do NOT mark processed — allow later reprocessing
                return;
            }

            // If order already final, attach and mark processed
            if (in_array($order->status, ['paid', 'cancelled'])) {
                $event->order_id = $order->id;
                $event->processed_at = now();
                $event->save();
                return;
            }

            $product = $order->hold->product()->lockForUpdate()->first();

            if ($result === 'succeeded' || $result === 'paid' || $result === 'success') {
                $order->status = 'paid';
                $order->paid_at = now();
                $order->save();

                // apply stock changes
                $product->stock = max(0, $product->stock - $order->qty);
                $product->reserved_count = max(0, $product->reserved_count - $order->qty);
                $product->save();
            } else {
                // failed payment -> cancel order and release reserved
                $order->status = 'cancelled';
                $order->save();

                $hold = $order->hold;
                $hold->status = 'cancelled';
                $hold->save();

                $product->reserved_count = max(0, $product->reserved_count - $order->qty);
                $product->save();
            }

            Cache::forget("product:{$product->id}:summary");

            $event->order_id = $order->id;
            $event->processed_at = now();
            $event->save();
        });
    }
}
