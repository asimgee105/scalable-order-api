<?php

namespace App\Http\Controllers\Api;

use App\Actions\Orders\CreateOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Resources\OrderResource;

class OrderController extends Controller
{
    /**
     * Logged-in user ke orders show karega.
     */
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

    /**
     * Single order show karega.
     */
public function show(Request $request, Order $order): JsonResponse
{
    $this->authorize('view', $order);

    $order->load([
        'items.product:id,name,sku',
    ]);

    return response()->json([
        'success' => true,
        'data' => new OrderResource($order),
    ]);
}
    /**
     * New order create karega.
     */
    public function store(
        StoreOrderRequest $request,
        CreateOrderAction $createOrder
    ): JsonResponse {
        $result = $createOrder->execute(
            $request->user(),
            $request->validated()
        );

        if ($result['duplicate']) {
            return response()->json([
                'success' => true,
                'duplicate' => true,
                'message' => 'Order was already created.',
                'data' => new OrderResource($result['order']),
            ], 200);
        }

        return response()->json([
            'success' => true,
            'duplicate' => false,
            'message' => 'Order created successfully.',
            'data' => $result['order'],
        ], 201);
    }
}