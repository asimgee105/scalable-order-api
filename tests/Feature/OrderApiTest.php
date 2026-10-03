<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_create_order(): void
    {
        $response = $this->postJson('/api/orders', [
            'items' => [
                [
                    'product_id' => 1,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_order(): void
    {
        $user = User::factory()->create();

        $product = Product::create([
            'name' => 'Laptop',
            'sku' => 'LAP-001',
            'price' => 1000,
            'stock' => 10,
            'is_active' => true,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/orders', [
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 2,
                    ],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 8,
        ]);
    }

    public function test_order_is_rejected_when_stock_is_insufficient(): void
    {
        $user = User::factory()->create();

        $product = Product::create([
            'name' => 'Mouse',
            'sku' => 'MOUSE-001',
            'price' => 50,
            'stock' => 2,
            'is_active' => true,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/orders', [
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 5,
                    ],
                ],
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 2,
        ]);
    }
}