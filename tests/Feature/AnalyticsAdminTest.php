<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsAdminTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->for($this->tenant)->create(['role' => $role]);

        $this->actingAs($user);
        $this->withHeader('X-Tenant-ID', $this->tenant->id);
        app()->instance('currentTenant', $this->tenant);

        return $user;
    }

    private function makeLead(array $attributes = []): Lead
    {
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => fake()->unique()->numerify('62812########')]);

        return Lead::factory()->for($customer)->create($attributes);
    }

    public function test_summary_reports_core_metrics(): void
    {
        $product = Product::factory()->for($this->tenant)->create();
        $campaign = Campaign::factory()->for($this->tenant)->create();
        $sales = User::factory()->for($this->tenant)->role('sales')->create();

        $this->makeLead(['product_id' => $product->id, 'campaign_id' => $campaign->id, 'status' => 'NEW', 'temperature' => 'HOT', 'estimated_value' => 250000000]);
        $this->makeLead(['status' => 'WON', 'estimated_value' => 300000000]);
        $this->makeLead(['status' => 'CONTACTED', 'estimated_value' => 100000000]);

        LeadEvent::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $this->makeLead(['status' => 'NEW'])->id,
            'event_type' => 'calculator_completed',
        ]);

        CampaignEvent::create([
            'tenant_id' => $this->tenant->id,
            'event_type' => 'whatsapp_click',
            'event_data' => [],
        ]);
        CampaignEvent::create([
            'tenant_id' => $this->tenant->id,
            'event_type' => 'whatsapp_click',
            'event_data' => [],
        ]);

        $this->actingAsRole('manager');

        $this->getJson('/api/v1/admin/analytics/summary')
            ->assertOk()
            ->assertJsonPath('data.total_leads', 4)
            ->assertJsonPath('data.hot_leads', 1)
            ->assertJsonPath('data.conversion_rate', 25)
            ->assertJsonPath('data.whatsapp_clicks', 2)
            ->assertJsonPath('data.calculator_completion', 1)
            ->assertJsonCount(1, 'data.top_products')
            ->assertJsonPath('data.top_products.0.name', $product->name)
            ->assertJsonCount(1, 'data.top_campaigns');
    }

    public function test_funnel_reports_stage_counts(): void
    {
        CampaignEvent::create(['tenant_id' => $this->tenant->id, 'event_type' => 'product_view']);
        CampaignEvent::create(['tenant_id' => $this->tenant->id, 'event_type' => 'product_view']);
        $this->makeLead(['status' => 'NEW']);
        $this->makeLead(['status' => 'WON']);

        $this->actingAsRole('owner');

        $this->getJson('/api/v1/admin/analytics/funnel')
            ->assertOk()
            ->assertJsonPath('data.product_views', 2)
            ->assertJsonPath('data.form_completes', 0)
            ->assertJsonPath('data.leads_created', 2)
            ->assertJsonPath('data.leads_won', 1);
    }

    public function test_response_time_averages_new_to_contacted(): void
    {
        $lead = $this->makeLead(['status' => 'CONTACTED', 'created_at' => now()->subMinutes(10)]);
        Lead::query()->where('id', $lead->id)->update(['created_at' => now()->subMinutes(10)]);

        LeadEvent::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'event_type' => 'contacted',
            'occurred_at' => now()->subMinutes(4),
        ]);

        $this->actingAsRole('manager');

        $this->getJson('/api/v1/admin/analytics/response-time')
            ->assertOk()
            ->assertJsonPath('data.contacted_total', 1)
            ->assertJsonPath('data.avg_seconds', 360);
    }

    public function test_sales_cannot_access_analytics(): void
    {
        $this->actingAsRole('sales');

        $this->getJson('/api/v1/admin/analytics/summary')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_summary_is_isolated_per_tenant(): void
    {
        $tenantB = Tenant::factory()->create();
        $customerB = Customer::factory()->for($tenantB)->create(['phone' => '6281300000001']);
        Lead::factory()->for($customerB)->create([
            'tenant_id' => $tenantB->id,
            'status' => 'NEW',
            'estimated_value' => 500000000,
        ]);

        $this->actingAsRole('manager');

        $this->getJson('/api/v1/admin/analytics/summary')
            ->assertOk()
            ->assertJsonPath('data.total_leads', 0)
            ->assertJsonPath('data.revenue_potential', 0);
    }
}
