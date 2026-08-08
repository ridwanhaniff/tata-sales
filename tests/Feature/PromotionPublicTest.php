<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Promotion;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PromotionPublicTest extends TestCase
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

    private function createPromotion(array $overrides = []): Promotion
    {
        return Promotion::factory()->for($this->tenant)->create($overrides);
    }

    public function test_active_only_returns_promotions_inside_valid_window(): void
    {
        $this->createPromotion(['name' => 'Aktif', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10), 'status' => 'active']);
        $this->createPromotion(['name' => 'Belum Mulai', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(10), 'status' => 'active']);
        $this->createPromotion(['name' => 'Sudah Berakhir', 'starts_at' => now()->subDays(20), 'ends_at' => now()->subDay(), 'status' => 'active']);
        $this->createPromotion(['name' => 'Draft', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10), 'status' => 'draft']);
        $this->createPromotion(['name' => 'Disabled', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10), 'status' => 'disabled']);

        $this->getWithTenant('/api/v1/promotions/active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Aktif')
            ->assertJsonStructure(['data' => [['id', 'name', 'discount_type', 'discount_value', 'starts_at', 'ends_at']]]);
    }

    public function test_active_filters_by_product_via_promotion_products(): void
    {
        $productA = Product::factory()->for($this->tenant)->create(['slug' => 'unit-a']);
        $productB = Product::factory()->for($this->tenant)->create(['slug' => 'unit-b']);

        $promoA = $this->createPromotion(['name' => 'Promo Unit A']);
        $promoA->products()->attach($productA, ['tenant_id' => $this->tenant->id]);

        $promoB = $this->createPromotion(['name' => 'Promo Unit B']);
        $promoB->products()->attach($productB, ['tenant_id' => $this->tenant->id]);

        $this->getWithTenant('/api/v1/promotions/active?product_id='.$productA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Promo Unit A');
    }

    public function test_active_filters_by_product_rule(): void
    {
        $product = Product::factory()->for($this->tenant)->create(['slug' => 'unit-rule']);

        $promo = $this->createPromotion(['name' => 'Promo Rule Produk']);
        $promo->rules()->create([
            'tenant_id' => $this->tenant->id,
            'rule_type' => 'product',
            'value' => ['product_id' => $product->id],
        ]);

        $this->getWithTenant('/api/v1/promotions/active?product_id='.$product->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Promo Rule Produk');
    }

    public function test_active_filters_by_category_rule(): void
    {
        $category = ProductCategory::factory()->for($this->tenant)->create(['slug' => 'suv']);
        $product = Product::factory()->for($this->tenant)->create(['category_id' => $category->id]);

        $promo = $this->createPromotion(['name' => 'Promo Kategori SUV']);
        $promo->rules()->create([
            'tenant_id' => $this->tenant->id,
            'rule_type' => 'category',
            'value' => ['category_id' => $category->id],
        ]);

        $this->getWithTenant('/api/v1/promotions/active?product_id='.$product->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Promo Kategori SUV');
    }

    public function test_promo_without_product_constraint_applies_to_all_products(): void
    {
        $product = Product::factory()->for($this->tenant)->create(['slug' => 'unit-any']);
        $this->createPromotion(['name' => 'Promo Semua Unit']);

        $this->getWithTenant('/api/v1/promotions/active?product_id='.$product->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Promo Semua Unit');
    }

    public function test_active_with_unknown_product_returns_empty(): void
    {
        $this->getWithTenant('/api/v1/promotions/active?product_id='.fake()->uuid())
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_tenant_b_cannot_see_tenant_a_promotions(): void
    {
        $tenantB = Tenant::factory()->create();
        $this->createPromotion(['name' => 'Promo Rahasia A']);

        $this->withHeader('X-Tenant-ID', $tenantB->id)
            ->getJson('/api/v1/promotions/active')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
