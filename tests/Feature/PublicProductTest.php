<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PublicProductTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function getWithTenant(string $uri): TestResponse
    {
        return $this->withHeader('X-Tenant-ID', $this->tenant->id)->getJson($uri);
    }

    public function test_public_product_list_only_shows_published_products(): void
    {
        Product::factory()->for($this->tenant)->create(['name' => 'Fronx Published', 'status' => 'published', 'published_at' => now()]);
        Product::factory()->for($this->tenant)->create(['name' => 'Draft', 'status' => 'draft', 'published_at' => null]);

        $this->getWithTenant('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Fronx Published')
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'base_price']], 'meta' => ['total']]);
    }

    public function test_public_product_list_can_filter_by_category_and_featured(): void
    {
        $category = ProductCategory::factory()->for($this->tenant)->create(['slug' => 'suv']);
        Product::factory()->for($this->tenant)->create([
            'category_id' => $category->id,
            'featured' => true,
            'status' => 'published',
            'published_at' => now(),
        ]);
        Product::factory()->for($this->tenant)->create(['status' => 'published', 'published_at' => now()]);

        $this->getWithTenant('/api/v1/products?category=suv&featured=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category.slug', 'suv');
    }

    public function test_public_product_detail_by_slug(): void
    {
        $category = ProductCategory::factory()->for($this->tenant)->create(['slug' => 'suv']);
        $product = Product::factory()->for($this->tenant)->create([
            'slug' => 'suzuki-fronx-glx',
            'category_id' => $category->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $product->variants()->create(['tenant_id' => $this->tenant->id, 'name' => 'AT', 'price' => 249500000]);
        $product->attributes()->create(['tenant_id' => $this->tenant->id, 'attribute_key' => 'engine', 'attribute_value' => '1500cc']);

        $this->getWithTenant('/api/v1/products/suzuki-fronx-glx')
            ->assertOk()
            ->assertJsonPath('data.name', $product->name)
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.attributes.engine', '1500cc')
            ->assertJsonPath('data.category.slug', 'suv');
    }

    public function test_public_product_detail_hides_draft_products(): void
    {
        Product::factory()->for($this->tenant)->create(['slug' => 'draft-product', 'status' => 'draft']);

        $this->getWithTenant('/api/v1/products/draft-product')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_public_product_not_found_returns_404(): void
    {
        $this->getWithTenant('/api/v1/products/tidak-ada')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_tenant_b_cannot_see_tenant_a_public_products(): void
    {
        $tenantB = Tenant::factory()->create();
        Product::factory()->for($this->tenant)->create([
            'slug' => 'rahasia-a',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->withHeader('X-Tenant-ID', $tenantB->id)
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeader('X-Tenant-ID', $tenantB->id)
            ->getJson('/api/v1/products/rahasia-a')
            ->assertStatus(404);
    }
}
