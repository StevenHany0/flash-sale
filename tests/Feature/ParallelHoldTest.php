<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Product;
use App\Http\Controllers\HoldController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

class ParallelHoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_parallel_holds_do_not_oversell()
    {
        // seed product with stock = 5
        $product = Product::create([
            'name' => 'Flash',
            'price' => 1000,
            'stock' => 5,
            'reserved_count' => 0,
        ]);

        $concurrency = 20;
        $success = 0;
        $conflicts = 0;

        $controller = new HoldController();

        // Simulate concurrent requests
        $requests = [];
        for ($i = 0; $i < $concurrency; $i++) {
            $requests[] = function () use ($controller, $product) {
                try {
                    $req = new Request([
                        'product_id' => $product->id,
                        'qty' => 1,
                    ]);
                    $response = $controller->store($req);
                    if ($response->getStatusCode() === 201) {
                        return 'success';
                    }
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    if ($e->getStatusCode() === 409) {
                        return 'conflict';
                    }
                } catch (\Exception $e) {
                    return 'error';
                }
            };
        }

        // Execute all "concurrent" closures
        foreach ($requests as $fn) {
            $result = $fn();
            if ($result === 'success') $success++;
            if ($result === 'conflict') $conflicts++;
        }

        $this->assertEquals(5, $success, "Expected 5 successful holds");
        $this->assertEquals($concurrency - 5, $conflicts, "Other requests should be conflicts");

        // confirm reserved_count and availability
        $product->refresh();
        $this->assertEquals(5, $product->reserved_count);
        $this->assertEquals(0, $product->stock - $product->reserved_count);
    }
}

