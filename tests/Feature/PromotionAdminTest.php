<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Promotion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionAdminTest extends TestCase
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

    public function test_manager_can_create_promotion_with_rules_and_products(): void
    {
        $product = Product::factory()->for($this->tenant)->create();
        $category = ProductCategory::factory()->for($this->tenant)->create();
        $this->actingAsRole('manager');

        $this->postJson('/api/v1/admin/promotions', [
            'name' => 'Promo Hari Raya',
            'description' => 'Diskon 10%',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'minimum_purchase' => 10000000,
            'usage_limit' => 100,
            'starts_at' => now()->subDay()->toISOString(),
            'ends_at' => now()->addDays(30)->toISOString(),
            'status' => 'active',
            'product_ids' => [$product->id],
            'rules' => [
                ['rule_type' => 'category', 'operator' => '=', 'value' => ['category_id' => $category->id]],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Promo Hari Raya')
            ->assertJsonCount(1, 'data.products')
            ->assertJsonCount(1, 'data.rules');

        $this->assertDatabaseHas('promotions', ['name' => 'Promo Hari Raya', 'tenant_id' => $this->tenant->id]);
        $this->assertDatabaseCount('promotion_products', 1);
        $this->assertDatabaseCount('promotion_rules', 1);
    }

    public function test_promotion_store_requires_valid_window(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/promotions', [
            'name' => 'Promo Invalid',
            'discount_type' => 'percentage',
            'discount_value' => 5,
            'starts_at' => now()->addDay()->toISOString(),
            'ends_at' => now()->toISOString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['ends_at']]]);
    }

    public function test_manager_can_update_promotion_and_replace_rules(): void
    {
        $promotion = Promotion::factory()->for($this->tenant)->create();
        $promotion->rules()->create([
            'tenant_id' => $this->tenant->id,
            'rule_type' => 'category',
            'value' => ['category_id' => ProductCategory::factory()->for($this->tenant)->create()->id],
        ]);
        $this->actingAsRole('manager');

        $this->putJson('/api/v1/admin/promotions/'.$promotion->id, [
            'name' => 'Promo Diperbarui',
            'discount_value' => 15,
            'rules' => [
                ['rule_type' => 'minimum_amount', 'operator' => '>=', 'value' => ['amount' => 5000000]],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Promo Diperbarui')
            ->assertJsonPath('data.discount_value', 15)
            ->assertJsonCount(1, 'data.rules');

        $this->assertDatabaseCount('promotion_rules', 1);
        $this->assertDatabaseHas('promotion_rules', ['rule_type' => 'minimum_amount']);
    }

    public function test_manager_can_delete_promotion_and_cascade_rules_products(): void
    {
        $product = Product::factory()->for($this->tenant)->create();
        $promotion = Promotion::factory()->for($this->tenant)->create();
        $promotion->products()->attach($product, ['tenant_id' => $this->tenant->id]);
        $promotion->rules()->create(['tenant_id' => $this->tenant->id, 'rule_type' => 'product', 'value' => ['product_id' => $product->id]]);
        $this->actingAsRole('manager');

        $this->deleteJson('/api/v1/admin/promotions/'.$promotion->id)->assertNoContent();

        $this->assertDatabaseCount('promotions', 0);
        $this->assertDatabaseCount('promotion_rules', 0);
        $this->assertDatabaseCount('promotion_products', 0);
    }

    public function test_sales_role_cannot_manage_promotions(): void
    {
        $this->actingAsRole('sales');

        $this->getJson('/api/v1/admin/promotions')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_index_filters_by_status_and_search(): void
    {
        Promotion::factory()->for($this->tenant)->create(['name' => 'Promo Aktif', 'status' => 'active']);
        Promotion::factory()->for($this->tenant)->create(['name' => 'Promo Draft', 'status' => 'draft']);
        $this->actingAsRole('owner');

        $this->getJson('/api/v1/admin/promotions?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Promo Aktif');

        $this->getJson('/api/v1/admin/promotions?search=Draft')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'draft');
    }

    public function test_discount_type_must_be_whitelisted(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/promotions', [
            'name' => 'Promo Salah',
            'discount_type' => 'gratis_aja',
            'starts_at' => now()->toISOString(),
            'ends_at' => now()->addDay()->toISOString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }
}
