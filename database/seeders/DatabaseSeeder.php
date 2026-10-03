<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Users
        $users = User::factory(10)->create();

        // Products
        Product::factory(30)->create();

        $users->each(function ($user) {

            // Each user gets 1 to 3 orders
            for ($i = 0; $i < random_int(1, 3); $i++) {

                DB::transaction(function () use ($user) {

                    $order = Order::create([
                        'user_id' => $user->id,
                        'status' => fake()->randomElement([
                            'pending',
                            'processing',
                            'completed',
                        ]),
                        'total_amount' => 0,
                    ]);

                    $products = Product::query()
                        ->inRandomOrder()
                        ->limit(random_int(1, 5))
                        ->get();

                    $total = 0;

                    foreach ($products as $product) {

                        $quantity = random_int(1, 3);

                        $subtotal = $product->price * $quantity;

                        OrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'quantity' => $quantity,
                            'unit_price' => $product->price,
                            'subtotal' => $subtotal,
                        ]);

                        $total += $subtotal;
                    }

                    $order->update([
                        'total_amount' => $total,
                    ]);
                });
            }
        });
    }
}