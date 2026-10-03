<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Events\OrderCreated;
use Illuminate\Support\Facades\Event;

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
            ->withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Idempotency-Key' => 'test-order-success-001',
            ])
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
            'idempotency_key' => 'test-order-success-001',
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
            ->withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Idempotency-Key' => 'test-insufficient-stock-001',
            ])
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

    public function test_same_idempotency_key_does_not_create_order_twice(): void
    {
        $user = User::factory()->create();

        $product = Product::create([
            'name' => 'Laptop',
            'sku' => 'IDEMP-LAP-001',
            'price' => 1000,
            'stock' => 10,
            'is_active' => true,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'test-order-001',
        ];

        $payload = [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
        ];

        $first = $this
            ->withHeaders($headers)
            ->postJson('/api/orders', $payload);

        $second = $this
            ->withHeaders($headers)
            ->postJson('/api/orders', $payload);

        $first
            ->assertCreated()
            ->assertJsonPath('success', true);

        $second
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('orders', 1);

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'idempotency_key' => 'test-order-001',
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 8,
        ]);
    }

    public function test_different_idempotency_key_creates_new_order(): void
    {
        $user = User::factory()->create();

        $product = Product::create([
            'name' => 'Keyboard',
            'sku' => 'KEY-001',
            'price' => 100,
            'stock' => 10,
            'is_active' => true,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $payload = [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
        ];

        $this
            ->withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Idempotency-Key' => 'order-key-001',
            ])
            ->postJson('/api/orders', $payload)
            ->assertCreated();

        $this
            ->withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Idempotency-Key' => 'order-key-002',
            ])
            ->postJson('/api/orders', $payload)
            ->assertCreated();

        $this->assertDatabaseCount('orders', 2);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 6,
        ]);
    }

    public function test_idempotency_key_is_required(): void
    {
        $user = User::factory()->create();

        $product = Product::create([
            'name' => 'Monitor',
            'sku' => 'MON-001',
            'price' => 500,
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
                        'quantity' => 1,
                    ],
                ],
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('orders', 0);
    }
    public function test_order_created_event_is_dispatched(): void
{
    Event::fake([
        OrderCreated::class,
    ]);

    $user = User::factory()->create();

    $product = Product::create([
        'name' => 'Headphones',
        'sku' => 'HEAD-001',
        'price' => 200,
        'stock' => 10,
        'is_active' => true,
    ]);

    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this
        ->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'event-test-order-001',
        ])
        ->postJson('/api/orders', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ]);

    $response->assertCreated();

    Event::assertDispatched(
        OrderCreated::class,
        function (OrderCreated $event) use ($user) {
            return $event->order->user_id === $user->id;
        }
    );
}
public function test_user_can_view_own_order(): void
{
    $user = User::factory()->create();

    $order = \App\Models\Order::create([
        'user_id' => $user->id,
        'idempotency_key' => 'own-order-001',
        'status' => 'pending',
        'total_amount' => 500,
    ]);

    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this
        ->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/orders/'.$order->id);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $order->id);
}
public function test_user_cannot_view_another_users_order(): void
{
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $order = \App\Models\Order::create([
        'user_id' => $userB->id,
        'idempotency_key' => 'private-order-001',
        'status' => 'pending',
        'total_amount' => 1000,
    ]);

    $token = $userA->createToken('test-token')->plainTextToken;

    $response = $this
        ->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/orders/'.$order->id);

    $response->assertForbidden();
}
public function test_order_resource_hides_internal_fields(): void
{
    $user = User::factory()->create();

    $order = \App\Models\Order::create([
        'user_id' => $user->id,
        'idempotency_key' => 'secret-idempotency-key',
        'status' => 'pending',
        'total_amount' => 750,
    ]);

    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this
        ->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/orders/'.$order->id);

    $response
        ->assertOk()
        ->assertJsonMissingPath('data.user_id')
        ->assertJsonMissingPath('data.idempotency_key')
        ->assertJsonMissingPath('data.updated_at');
}
public function test_user_only_sees_own_orders(): void
{
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $orderA = \App\Models\Order::create([
        'user_id' => $userA->id,
        'idempotency_key' => 'user-a-order',
        'status' => 'pending',
        'total_amount' => 100,
    ]);

    $orderB = \App\Models\Order::create([
        'user_id' => $userB->id,
        'idempotency_key' => 'user-b-order',
        'status' => 'pending',
        'total_amount' => 200,
    ]);

    $token = $userA->createToken('test-token')->plainTextToken;

    $response = $this
        ->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/orders');

    $response
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertCount(
        1,
        $response->json('data')
    );

    $response->assertJsonPath(
        'data.0.id',
        $orderA->id
    );

    $response->assertJsonMissing([
        'id' => $orderB->id,
    ]);
}
}