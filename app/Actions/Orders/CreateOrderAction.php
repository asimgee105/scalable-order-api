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

                $total = 0;

                foreach ($data['items'] as $item) {

                    /*
                    |--------------------------------------------------------------------------
                    | 3. Lock product row
                    |--------------------------------------------------------------------------
                    */

                    $product = Product::query()
                        ->whereKey($item['product_id'])
                        ->lockForUpdate()
                        ->firstOrFail();

                    /*
                    |--------------------------------------------------------------------------
                    | 4. Product validation
                    |--------------------------------------------------------------------------
                    */

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

                    /*
                    |--------------------------------------------------------------------------
                    | 5. Calculate price
                    |--------------------------------------------------------------------------
                    */

                    $unitPrice = (float) $product->price;

                    $subtotal = round(
                        $unitPrice * $quantity,
                        2
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 6. Create order item
                    |--------------------------------------------------------------------------
                    */

                    $order->items()->create([
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'subtotal' => $subtotal,
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | 7. Reduce stock
                    |--------------------------------------------------------------------------
                    */

                    $product->decrement(
                        'stock',
                        $quantity
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 8. Remove stale product cache
                    |--------------------------------------------------------------------------
                    */

                    Cache::forget(
                        "product:{$product->id}"
                    );

                    $total += $subtotal;
                }

                /*
                |--------------------------------------------------------------------------
                | 9. Final order total
                |--------------------------------------------------------------------------
                */

                $order->update([
                    'total_amount' => round($total, 2),
                ]);

                return $order;
            });

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