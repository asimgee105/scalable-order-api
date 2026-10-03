<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        $products = Product::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

   public function show(int $id): JsonResponse
{
    $product = Cache::remember(
        "product:{$id}",
        now()->addMinutes(5),
        function () use ($id) {
            return Product::findOrFail($id);
        }
    );

    return response()->json([
        'success' => true,
        'data' => $product,
    ]);
}
}