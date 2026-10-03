<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
  public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with([
                'items.product:id,name,sku',
            ])
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load([
            'user:id,name,email',
            'items.product:id,name,sku',
        ]);

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }
public function store(StoreOrderRequest $request): JsonResponse
{
    $data = $request->validated();
    $user = $request->user();
    $order = DB::transaction(function () use ($data, $user) {

            $order = Order::create([
                'user_id' => $user->id,
                'status' => 'pending',
                'total_amount' => 0,
            ]);

        $total = 0;

        foreach ($data['items'] as $item) {

            $product = Product::query()
                ->whereKey($item['product_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $product->is_active) {
                throw ValidationException::withMessages([
                    'items' => [
                        "Product {$product->name} is inactive.",
                    ],
                ]);
            }

            $quantity = $item['quantity'];

            if ($product->stock < $quantity) {
                throw ValidationException::withMessages([
                    'items' => [
                        "Insufficient stock for {$product->name}. Available: {$product->stock}",
                    ],
                ]);
            }

            $unitPrice = (float) $product->price;
            $subtotal = round($unitPrice * $quantity, 2);

            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
            ]);

            $product->decrement('stock', $quantity);

            $total += $subtotal;
        }

        $order->update([
            'total_amount' => round($total, 2),
        ]);

        return $order;
    });

    $order->load([
        'user:id,name,email',
        'items.product:id,name,sku,stock',
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Order created successfully.',
        'data' => $order,
    ], 201);
}
}