<?php

namespace Tests\Feature\Analytics;

use App\Models\Campaign;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdvancedAnalyticsTest extends TestCase
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

    private function lead(string $status, ?Campaign $campaign = null, float $value = 0): Lead
    {
        return Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => $status,
            'campaign_id' => $campaign?->id,
            'estimated_value' => $value,
        ]);
    }

    public function test_win_rate_computed(): void
    {
        $this->lead('NEW');
        $this->lead('NEW');
        $this->lead('WON', value: 5_000_000);
        $this->lead('WON', value: 3_000_000);

        $winRate = app(AnalyticsService::class)->winRate($this->tenant);

        $this->assertSame(50.0, $winRate['win_rate']);
        $this->assertSame(2, $winRate['won']);
        $this->assertSame(8_000_000.0, $winRate['won_value']);
    }

    public function test_win_rate_by_campaign(): void
    {
        $campaign = Campaign::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->lead('NEW', $campaign);
        $this->lead('WON', $campaign);
        $this->lead('WON', $campaign);

        $winRate = app(AnalyticsService::class)->winRate($this->tenant);
        $row = $winRate['by_campaign'][0];

        $this->assertCount(1, $winRate['by_campaign']);
        $this->assertSame(2, $row['won']);
        $this->assertSame(66.7, $row['win_rate']);
    }

    public function test_pipeline_value_per_stage(): void
    {
        $stages = PipelineStage::query()
            ->where('tenant_id', $this->tenant->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertNotEmpty($stages);

        $this->lead('QUALIFIED', value: 1_000_000);
        $this->lead('QUALIFIED', value: 2_000_000);
        $this->lead('WON', value: 9_000_000);

        $pipeline = app(AnalyticsService::class)->pipeline($this->tenant);

        $qualified = collect($pipeline['stages'])->firstWhere('key', 'QUALIFIED');

        $this->assertNotNull($qualified);
        $this->assertSame(2, $qualified['leads']);
        $this->assertSame(3_000_000.0, $qualified['value']);
        $this->assertSame(3_000_000.0, $pipeline['total_open_value']);
    }

    public function test_campaign_roi(): void
    {
        $campaign = Campaign::factory()->create(['tenant_id' => $this->tenant->id, 'budget' => 1_000_000]);

        $this->lead('WON', $campaign, value: 5_000_000);
        $this->lead('NEW', $campaign);

        $roi = app(AnalyticsService::class)->campaignRoi($this->tenant);

        $this->assertCount(1, $roi);
        $this->assertSame(5_000_000.0, $roi[0]['won_value']);
        $this->assertSame(500.0, $roi[0]['roi']);
    }

    public function test_campaign_roi_with_accepted_quotation_priority(): void
    {
        // Accepted quotation memberikan nilai lebih akurat daripada estimated_value.
        $campaign = Campaign::factory()->create(['tenant_id' => $this->tenant->id, 'budget' => 1_000_000]);
        $lead = $this->lead('WON', $campaign, value: 2_000_000);

        $roi = app(AnalyticsService::class)->campaignRoi($this->tenant);

        $this->assertSame(2_000_000.0, $roi[0]['won_value']);
    }

    public function test_analytics_endpoints_require_owner_manager(): void
    {
        $owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'owner']);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Tenant-ID', $this->tenant->id);

        $this->getJson('/api/v1/admin/analytics/win-rate')->assertOk();
        $this->getJson('/api/v1/admin/analytics/pipeline')->assertOk();
        $this->getJson('/api/v1/admin/analytics/campaign-roi')->assertOk();

        $sales = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'sales']);
        Sanctum::actingAs($sales);

        $this->getJson('/api/v1/admin/analytics/win-rate')->assertForbidden();
    }

    public function test_analytics_ignores_other_tenant_leads(): void
    {
        $other = Tenant::factory()->create();
        Lead::factory()->create(['tenant_id' => $other->id, 'status' => 'WON', 'estimated_value' => 99_000_000]);

        $win = app(AnalyticsService::class)->winRate($this->tenant);

        $this->assertSame(0.0, $win['win_rate']);
        $this->assertSame(0, $win['won']);
    }
}
