<?php

namespace App\Actions\Orders;

use App\Events\OrderCreated;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOrderAction
{
    public function execute(User $user, array $data): array
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Idempotency check
        |--------------------------------------------------------------------------
        */

        $existingOrder = Order::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->with('items.product')
            ->first();

        if ($existingOrder) {
            return [
                'order' => $existingOrder,
                'duplicate' => true,
            ];
        }

        try {

            /*
            |--------------------------------------------------------------------------
            | 2. Database Transaction
            |--------------------------------------------------------------------------
            */

         $order = DB::transaction(function () use ($user, $data) {

            $order = Order::create([
                'user_id' => $user->id,
                'idempotency_key' => $data['idempotency_key'],
                'status' => 'pending',
                'total_amount' => 0,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Product IDs ko same order mein lock karo
            |--------------------------------------------------------------------------
            */

            $productIds = collect($data['items'])
                ->pluck('product_id')
                ->sort()
                ->values();

            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $total = 0;

            foreach ($data['items'] as $item) {

                $product = $products->get($item['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Product {$item['product_id']} was not found.",
                        ],
                    ]);
                }

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

                $subtotal = round(
                    $unitPrice * $quantity,
                    2
                );

                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                ]);

                $product->decrement(
                    'stock',
                    $quantity
                );

                Cache::forget(
                    "product:{$product->id}"
                );

                $total += $subtotal;
            }

            $order->update([
                'total_amount' => round($total, 2),
            ]);

            return $order;

        }, 3);

        } catch (QueryException $e) {

            /*
            |--------------------------------------------------------------------------
            | Concurrent duplicate request protection
            |--------------------------------------------------------------------------
            */

            $existingOrder = Order::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->with('items.product')
                ->first();

            if ($existingOrder) {
                return [
                    'order' => $existingOrder,
                    'duplicate' => true,
                ];
            }

            throw $e;
        }

        /*
        |--------------------------------------------------------------------------
        | 10. Event after successful transaction
        |--------------------------------------------------------------------------
        */

        OrderCreated::dispatch($order);

        $order->load([
            'items.product:id,name,sku,stock',
        ]);

        return [
            'order' => $order,
            'duplicate' => false,
        ];
    }
}