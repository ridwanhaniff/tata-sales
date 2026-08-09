<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignAdminTest extends TestCase
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

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->for($this->tenant)->create(['role' => $role]);

        $this->actingAs($user);
        $this->withHeader('X-Tenant-ID', $this->tenant->id);

        return $user;
    }

    public function test_owner_can_create_campaign_with_sources(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/campaigns', [
            'name' => 'GoPay Promo Agustus',
            'utm_campaign' => 'agustus-2026',
            'status' => 'active',
            'budget' => 15000000,
            'sources' => [
                ['utm_source' => 'instagram', 'utm_medium' => 'social'],
                ['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_content' => 'lead-form'],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'GoPay Promo Agustus')
            ->assertJsonCount(2, 'data.sources');

        $this->assertDatabaseHas('campaign_sources', [
            'tenant_id' => $this->tenant->id,
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
        ]);
        $this->assertDatabaseHas('campaigns', [
            'tenant_id' => $this->tenant->id,
            'utm_campaign' => 'agustus-2026',
        ]);
    }

    public function test_campaign_lead_auto_matches_utm_campaign(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/campaigns', [
            'name' => 'Kampanye FB',
            'utm_campaign' => 'fb-agustus',
            'status' => 'active',
        ])->assertCreated();

        $this->postJson('/api/v1/leads', [
            'customer' => ['name' => 'Caca', 'phone' => '081290000001'],
            'utm' => ['utm_campaign' => 'fb-agustus', 'utm_source' => 'facebook'],
            'consent_marketing' => true,
        ])->assertCreated();

        $lead = Lead::query()->latest('id')->firstOrFail();
        $this->assertNotNull($lead->campaign_id);
        $this->assertSame('Kampanye FB', $lead->campaign->name);
    }

    public function test_manager_can_update_campaign_and_replace_sources(): void
    {
        $this->actingAsRole('manager');
        $campaign = Campaign::factory()->for($this->tenant)->create();

        $this->putJson('/api/v1/admin/campaigns/'.$campaign->id, [
            'name' => 'Campaign V2',
            'status' => 'paused',
            'sources' => [
                ['utm_source' => 'tiktok', 'utm_medium' => 'social'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonCount(1, 'data.sources');

        $this->assertDatabaseHas('campaign_sources', [
            'campaign_id' => $campaign->id,
            'utm_source' => 'tiktok',
        ]);
        $this->assertSame(1, $campaign->sources()->count());
    }

    public function test_campaign_can_be_deleted(): void
    {
        $this->actingAsRole('owner');
        $campaign = Campaign::factory()->for($this->tenant)->create();
        $campaign->sources()->create(['tenant_id' => $this->tenant->id, 'utm_source' => 'google']);

        $this->deleteJson('/api/v1/admin/campaigns/'.$campaign->id)->assertNoContent();

        $this->assertDatabaseMissing('campaigns', ['id' => $campaign->id]);
        $this->assertDatabaseMissing('campaign_sources', ['campaign_id' => $campaign->id]);
    }

    public function test_content_manager_can_manage_campaigns(): void
    {
        $this->actingAsRole('content_manager');

        $this->postJson('/api/v1/admin/campaigns', [
            'name' => 'CM Campaign',
            'utm_campaign' => 'cm-1',
        ])->assertCreated();
    }

    public function test_sales_cannot_manage_campaigns(): void
    {
        $this->actingAsRole('sales');

        $this->postJson('/api/v1/admin/campaigns', ['name' => 'x'])
            ->assertStatus(403);
    }

    public function test_ends_at_before_starts_at_is_rejected(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/campaigns', [
            'name' => 'Bad window',
            'starts_at' => '2026-08-20T00:00:00Z',
            'ends_at' => '2026-08-01T00:00:00Z',
        ])->assertStatus(422);
    }
}
