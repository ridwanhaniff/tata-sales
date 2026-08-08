<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductAdminTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->for($this->tenant)->create(['role' => $role]);

        $this->actingAs($user);
        $this->withHeader('X-Tenant-ID', $this->tenant->id);
        app()->instance('currentTenant', $this->tenant);

        return $user;
    }

    public function test_owner_can_create_product_with_variants_and_attributes(): void
    {
        $category = ProductCategory::factory()->for($this->tenant)->create();
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/products', [
            'name' => 'Suzuki Fronx GLX',
            'slug' => 'suzuki-fronx-glx',
            'category_id' => $category->id,
            'base_price' => 249500000,
            'short_description' => 'Compact SUV',
            'variants' => [
                ['name' => 'Automatic', 'price' => 249500000, 'stock' => 5],
                ['name' => 'Manual', 'price' => 239500000, 'stock' => 3],
            ],
            'attributes' => [
                ['key' => 'engine', 'value' => '1500cc', 'type' => 'text'],
                ['key' => 'seats', 'value' => '5', 'type' => 'number'],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Suzuki Fronx GLX')
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonPath('data.attributes.engine', '1500cc');

        $this->assertDatabaseHas('products', ['slug' => 'suzuki-fronx-glx', 'tenant_id' => $this->tenant->id]);
        $this->assertDatabaseCount('product_variants', 2);
        $this->assertDatabaseCount('product_attributes', 2);
    }

    public function test_owner_can_update_and_delete_product(): void
    {
        $product = Product::factory()->for($this->tenant)->create();
        $this->actingAsRole('owner');

        $this->putJson("/api/v1/admin/products/{$product->id}", ['name' => 'Fronx Zeta'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Fronx Zeta');

        $this->deleteJson("/api/v1/admin/products/{$product->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_publish_and_unpublish(): void
    {
        $product = Product::factory()->for($this->tenant)->create(['status' => 'draft', 'published_at' => null]);
        $this->actingAsRole('owner');

        $this->postJson("/api/v1/admin/products/{$product->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'published']);

        $this->postJson("/api/v1/admin/products/{$product->id}/unpublish")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'draft']);
    }

    public function test_sales_cannot_create_product(): void
    {
        $this->actingAsRole('sales');

        $this->postJson('/api/v1/admin/products', ['name' => 'Boom'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_guest_cannot_create_product(): void
    {
        $this->postJson('/api/v1/admin/products', ['name' => 'Boom'])
            ->assertStatus(401);
    }

    public function test_owner_can_upload_and_convert_image_to_webp(): void
    {
        Storage::fake('public');

        $product = Product::factory()->for($this->tenant)->create();
        $this->actingAsRole('owner');

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $this->postJson("/api/v1/admin/products/{$product->id}/images", [
            'images' => [UploadedFile::fake()->createWithContent('car.png', $png)],
        ])
            ->assertCreated()
            ->assertJsonCount(1, 'data.images');

        $this->assertDatabaseCount('product_images', 1);

        $url = ProductImage::first()->url;
        $path = str_replace('/storage/', '', parse_url($url, PHP_URL_PATH));

        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith('.webp', $path);
    }

    public function test_owner_can_manage_categories(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/product-categories', ['name' => 'SUV', 'slug' => 'suv'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'suv');

        $category = ProductCategory::where('slug', 'suv')->firstOrFail();

        $this->putJson("/api/v1/admin/product-categories/{$category->id}", ['name' => 'SUV Compact'])
            ->assertOk()
            ->assertJsonPath('data.name', 'SUV Compact');

        $this->deleteJson("/api/v1/admin/product-categories/{$category->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('product_categories', ['id' => $category->id]);
    }
}
