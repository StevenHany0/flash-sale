<?php

namespace App\Jobs;

use App\Models\Hold;
use App\Services\DbRetry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExpireHoldJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public Hold $hold;

    public function __construct(Hold $hold)
    {
        $this->hold = $hold;
    }

    public function handle(): void
{
    DbRetry::run(function () {

        $updated = \DB::affectingStatement(
            'UPDATE holds SET status = ? WHERE id = ? AND status = ?',
            ['expired', $this->hold->id, 'active']
        );

        if ($updated === 0) return;

        // Step 1: decrement
        \DB::table('products')
            ->where('id', $this->hold->product_id)
            ->decrement('reserved_count', $this->hold->qty);

        // Step 2: clamp to zero (sqlite safe)
        \DB::table('products')
            ->where('id', $this->hold->product_id)
            ->where('reserved_count', '<', 0)
            ->update(['reserved_count' => 0]);

        Cache::forget("product:{$this->hold->product_id}:summary");
    });
}

}
