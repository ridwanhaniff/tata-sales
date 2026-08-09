<?php

namespace Tests\Feature;

use App\Models\Calculator;
use App\Models\Followup;
use App\Models\FollowupStep;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
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

    /**
     * Ekstensi MVP2 (§142 item 18): workflow dasar + follow-up terjadwal +
     * notifikasi sampai ke sales.
     */
    public function test_workflow_and_followup_pipeline(): void
    {
        $owner = User::factory()->for($this->tenant)->role('owner')->create();
        $sales = User::factory()->for($this->tenant)->role('sales')->create();

        $workflow = Workflow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Welcome workflow',
            'trigger_event' => 'lead_created',
            'status' => 'active',
            'definition' => [],
        ]);
        $workflow->nodes()->create(['tenant_id' => $this->tenant->id, 'node_type' => 'trigger', 'config' => [], 'sort_order' => 0]);
        $workflow->nodes()->create(['tenant_id' => $this->tenant->id, 'node_type' => 'action', 'config' => ['action' => 'create_followup', 'message' => 'Halo {customer_name}, ini {product_name} impian Anda?', 'delay_minutes' => 5], 'sort_order' => 1]);
        $workflow->nodes()->create(['tenant_id' => $this->tenant->id, 'node_type' => 'end', 'config' => [], 'sort_order' => 2]);

        FollowupStep::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Follow-up awal',
            'trigger_event' => 'lead_created',
            'delay_minutes' => 30,
            'message' => 'Halo {customer_name}, kabar baik?',
            'action' => 'create_followup',
            'sort_order' => 0,
            'status' => 'active',
        ]);

        $this->actingAs($owner);
        $this->withHeader('X-Tenant-ID', $this->tenant->id);

        $this->postJson('/api/v1/leads', [
            'customer' => ['name' => 'Rudi', 'phone' => '081298765431'],
            'source' => 'form',
            'consent_marketing' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('workflow_runs', [
            'tenant_id' => $this->tenant->id,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('followups', [
            'tenant_id' => $this->tenant->id,
            'channel' => 'whatsapp',
            'status' => 'pending',
        ]);

        $this->assertSame(2, Followup::query()->count());

        $this->travel(2)->hours();
        $this->artisan('followups:send')->assertSuccessful();

        $this->assertDatabaseHas('followups', [
            'tenant_id' => $this->tenant->id,
            'status' => 'sent',
        ]);

        $this->assertDatabaseHas('notifications', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $sales->id,
            'type' => 'followup_sent',
        ]);

        $this->assertSame(0, Followup::query()->where('status', 'pending')->count());
    }
}
