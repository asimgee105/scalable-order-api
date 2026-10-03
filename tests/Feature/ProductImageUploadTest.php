<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductImageUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_upload_product_image(): void
    {
        Storage::fake('public');

        config([
            'filesystems.default' => 'public',
        ]);

        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $product = Product::create([
            'name' => 'Laptop',
            'sku' => 'IMG-LAP-001',
            'price' => 1000,
            'stock' => 10,
            'is_active' => true,
        ]);

        $image = UploadedFile::fake()
            ->image('laptop.jpg');

        $response = $this->post(
            '/api/products/'.$product->id.'/image',
            [
                'image' => $image,
            ],
            [
                'Accept' => 'application/json',
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $product->refresh();

        $this->assertNotNull(
            $product->image_path
        );

        Storage::disk('public')
            ->assertExists($product->image_path);
    }

    public function test_invalid_file_is_rejected(): void
    {
        Storage::fake('public');

        config([
            'filesystems.default' => 'public',
        ]);

        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $product = Product::create([
            'name' => 'Mouse',
            'sku' => 'IMG-MOUSE-001',
            'price' => 50,
            'stock' => 10,
            'is_active' => true,
        ]);

        $file = UploadedFile::fake()
            ->create(
                'malicious.exe',
                100,
                'application/octet-stream'
            );

        $response = $this->post(
            '/api/products/'.$product->id.'/image',
            [
                'image' => $file,
            ],
            [
                'Accept' => 'application/json',
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }
}