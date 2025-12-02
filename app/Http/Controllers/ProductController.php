<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    public function show($id)
    {
        $cacheKey = "product:{$id}:summary";
        $summary = Cache::remember($cacheKey, 10, function () use ($id) {
            $product = Product::findOrFail($id);
            return [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $product->price,
                'stock' => $product->stock,
                'reserved' => $product->reserved_count,
                'available' => $product->stock - $product->reserved_count,
            ];
        });

        return response()->json($summary);
    }
}
