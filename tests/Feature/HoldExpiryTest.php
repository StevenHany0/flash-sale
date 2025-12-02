<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Product;
use App\Models\Hold;
use App\Jobs\ExpireHoldJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

class HoldExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_hold_expiry_releases_reserved()
    {
        // create product
        $product = Product::create(['name'=>'Item','price'=>1000,'stock'=>10,'reserved_count'=>0]);

        // create hold and increment reserved_count
        $product->reserved_count += 2;
        $product->save();

        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 2,
            'status' => 'active',
            'expires_at' => now()->subSeconds(10), // already expired
        ]);

        // ensure cache key exists & then cleared by job
        Cache::put("product:{$product->id}:summary", ['dummy'], 60);

        // run expiry job synchronously to simulate worker
        $job = new ExpireHoldJob($hold);
        $job->handle();

        $product->refresh();
        $hold->refresh();

        $this->assertEquals(0, $product->reserved_count);
        $this->assertEquals('expired', $hold->status);
        $this->assertFalse(Cache::has("product:{$product->id}:summary"));
    }
}
