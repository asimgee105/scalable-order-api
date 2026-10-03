<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadProductImageRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

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

    public function uploadImage(
        UploadProductImageRequest $request,
        Product $product
    ): JsonResponse {
        $disk = config('filesystems.default');

        if ($product->image_path) {
            Storage::disk($disk)->delete(
                $product->image_path
            );
        }

        $path = Storage::disk($disk)->putFile(
            'products',
            $request->file('image')
        );

        $product->update([
            'image_path' => $path,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product image uploaded successfully.',
            'data' => [
                'product_id' => $product->id,
                'image_path' => $path,
                'image_url' => Storage::disk($disk)->url($path),
            ],
        ]);
    }
}
