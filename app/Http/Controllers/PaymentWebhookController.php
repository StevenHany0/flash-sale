<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PaymentEvent;
use App\Services\PaymentEventProcessor;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function handle(Request $req)
    {
        $idempotencyKey = $req->header('Idempotency-Key') ?: $req->input('idempotency_key');

        if (!$idempotencyKey) {
            return response()->json(['error' => 'Missing idempotency key'], 400);
        }

        // 1) Check duplicate BEFORE insert
        $existing = PaymentEvent::where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            // Duplicate event detected before insert -> no-op
            return response()->json(['ok' => true]); // already processed
        }

        // 2) Try insert — may fail due to race condition
        try {
            $event = PaymentEvent::create([
                'idempotency_key' => $idempotencyKey,
                'external_payment_id' => $req->input('external_payment_id'),
                'payload' => $req->all(),
                'result' => $req->input('result'),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {

            // Unique constraint violation → duplicate
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $event = PaymentEvent::where('idempotency_key', $idempotencyKey)->first();
                // Duplicate event detected during insert race -> no-op
                return response()->json(['ok' => true]); // already processed
            }

            throw $e;
        }

        // 3) Process the event normally
        app(PaymentEventProcessor::class)->processEvent($event);

        return response()->json(['ok' => true]);
    }

}
