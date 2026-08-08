<?php

namespace Tests\Feature;

use App\Models\Calculator;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test alur MVP1 (§142): tenant dibuat → owner login → product dibuat
 * & publish → visitor lihat product/promo → calculator jalan → lead submit &
 * masuk DB & ter-score & ter-assign → sales lihat lead → analytics jalan.
 */
class Mvp1SmokeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
        $this->seed([PipelineStageSeeder::class]);
    }

    public function test_full_mvp1_journey(): void
    {
        $owner = User::factory()->for($this->tenant)->role('owner')->create();
        $sales = User::factory()->for($this->tenant)->role('sales')->create();

        // owner publish product
        $this->actingAs($owner);
        $this->withHeader('X-Tenant-ID', $this->tenant->id);

        $productResponse = $this->postJson('/api/v1/admin/products', [
            'name' => 'Fronx GLX',
            'slug' => 'fronx-glx',
            'base_price' => 249500000,
            'status' => 'published',
            'featured' => true,
        ])->assertCreated();
        $product = Product::query()->find($productResponse->json('data.id'));
        $this->assertNotNull($product);

        $this->postJson('/api/v1/admin/products/'.$product->id.'/publish')->assertOk();

        $this->postJson('/api/v1/admin/promotions', [
            'name' => 'Promo Lebaran',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'starts_at' => now()->subDay()->toISOString(),
            'ends_at' => now()->addDays(30)->toISOString(),
            'status' => 'active',
        ])->assertCreated();

        $calculator = Calculator::factory()->for($this->tenant)->credit()->create();

        // visitor: lihat produk & promo aktif (tanpa auth)
        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->getJson('/api/v1/products/fronx-glx')
            ->assertOk()
            ->assertJsonPath('data.name', 'Fronx GLX');

        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->getJson('/api/v1/promotions/active')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // visitor: hitung cicilan
        $calculation = $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/calculators/'.$calculator->id.'/calculate', [
                'inputs' => ['price' => 249500000, 'dp' => 50000000, 'tenor' => 60, 'interest' => 6.5],
                'product_id' => $product->id,
            ])
            ->assertOk()
            ->json('data');

        // visitor: isi form lead (dengan hasil kalkulator)
        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/leads', [
                'customer' => ['name' => 'Budi Santoso', 'phone' => '081298765432', 'email' => 'budi@example.com'],
                'product_id' => $product->id,
                'calculator_session_id' => $calculation['session_id'],
                'consent_marketing' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.temperature', 'WARM')
            ->assertJsonPath('data.assigned_to.name', $sales->name);

        // lead benar-benar masuk DB, ter-score, ter-assign
        $this->assertDatabaseHas('leads', [
            'tenant_id' => $this->tenant->id,
            'status' => 'NEW',
            'assigned_to' => $sales->id,
            'score' => 45,
        ]);

        // sales login: lihat lead miliknya di dashboard
        $this->actingAs($sales);
        $this->withHeader('X-Tenant-ID', $this->tenant->id);

        $this->getJson('/api/v1/admin/leads')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/admin/sales/dashboard')
            ->assertOk()
            ->assertJsonPath('data.my_leads_total', 1)
            ->assertJsonPath('data.hot_leads', 0);

        // owner: analytics selesai jalan
        $this->actingAs($owner);

        $this->getJson('/api/v1/admin/analytics/summary')
            ->assertOk()
            ->assertJsonPath('data.total_leads', 1)
            ->assertJsonPath('data.calculator_completion', 1)
            ->assertJsonCount(1, 'data.top_products');
    }
}
