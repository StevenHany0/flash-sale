<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\ExpireHoldJob;
use App\Models\Hold;
use App\Models\Product;
use App\Services\DbRetry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HoldController extends Controller
{
    public function store(Request $req)
{
    $req->validate([
        'product_id' => 'required|integer|exists:products,id',
        'qty' => 'required|integer|min:1',
    ]);

    $productId = (int) $req->product_id;
    $qty = (int) $req->qty;
    $ttlSeconds = 120;

    $result = DbRetry::run(function () use ($productId, $qty, $ttlSeconds) {

    return DB::transaction(function () use ($productId, $qty, $ttlSeconds) {

        // row lock — this is the key fix
        $product = DB::table('products')
            ->where('id', $productId)
            ->lockForUpdate()
            ->first();

        if (!$product) {
            abort(404, "Product not found");
        }

        if ($product->stock - $product->reserved_count < $qty) {
            abort(409, "Not enough stock");
        }

        // Update reserved_count
        DB::table('products')
            ->where('id', $productId)
            ->update([
                'reserved_count' => $product->reserved_count + $qty,
            ]);

        // Create hold record (safe because product_id exists)
        $hold = Hold::create([
            'product_id' => $productId,
            'qty' => $qty,
            'status' => 'active',
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);

        Cache::forget("product:{$productId}:summary");

        ExpireHoldJob::dispatch($hold)->delay(now()->addSeconds($ttlSeconds + 5));

        return [
            'hold_id' => $hold->id,
            'expires_at' => $hold->expires_at->toDateTimeString(),
        ];
    });
});


    return response()->json($result, 201);
}

}
