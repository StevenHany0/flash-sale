<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Hold;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Services\DbRetry;
use App\Services\PaymentEventProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class OrderController extends Controller
{
    public function store(Request $req)
    {
        $holdId = (int) $req->input('hold_id');

        $order = DbRetry::run(function () use ($holdId) {

            $hold = Hold::where('id', $holdId)->lockForUpdate()->firstOrFail();

            if ($hold->status !== 'active' || ($hold->expires_at && $hold->expires_at->isPast())) {
                abort(400, 'Hold invalid or expired');
            }

            $hold->status = 'used';
            $hold->save();

            $order = Order::create([
                'hold_id' => $hold->id,
                'qty' => $hold->qty,
                'status' => 'pending',
            ]);

            Cache::forget("product:{$hold->product_id}:summary");

            // Find ANY pending payment events referring to this order
            $events = PaymentEvent::whereNull('processed_at')
                ->where(function ($q) use ($hold, $order) {
                    $q->where('order_id', $order->id)
                        ->orWhereJsonContains('payload->order_id', $order->id)
                        ->orWhereJsonContains('payload->hold_id', $hold->id);
                })
                ->get();

            foreach ($events as $ev) {
                app(PaymentEventProcessor::class)->processEvent($ev);
            }

            return $order;
        });

        return response()->json(['order_id' => $order->id, 'status' => $order->status], 201);
    }
}
