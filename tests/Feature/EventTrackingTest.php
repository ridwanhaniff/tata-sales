<?php

namespace Tests\Feature;

use App\Models\CampaignEvent;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    public function test_can_track_page_view_event(): void
    {
        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/events', [
                'event_type' => 'page_view',
                'visitor_id' => 'visitor-123',
                'event_data' => ['path' => '/', 'referrer' => 'facebook'],
            ])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['event_id', 'visitor_id']]);

        $this->assertDatabaseHas('campaign_events', [
            'tenant_id' => $this->tenant->id,
            'visitor_id' => 'visitor-123',
            'event_type' => 'page_view',
        ]);
    }

    public function test_track_product_view_event(): void
    {
        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/events', [
                'event_type' => 'product_view',
                'visitor_id' => 'visitor-456',
                'event_data' => ['product_slug' => 'fronx-glx'],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('campaign_events', ['event_type' => 'product_view', 'visitor_id' => 'visitor-456']);
    }

    public function test_invalid_event_type_is_rejected(): void
    {
        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/events', ['event_type' => 'hack_attempt'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_events_are_isolated_per_tenant(): void
    {
        $tenantB = Tenant::factory()->create();

        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/events', ['event_type' => 'page_view', 'visitor_id' => 'v-a']);

        CampaignEvent::where('tenant_id', $tenantB->id)->delete();

        $this->withHeader('X-Tenant-ID', $tenantB->id)
            ->postJson('/api/v1/events', ['event_type' => 'cta_click', 'visitor_id' => 'v-b']);

        $this->assertDatabaseHas('campaign_events', ['tenant_id' => $tenantB->id, 'visitor_id' => 'v-b']);
        $this->assertDatabaseMissing('campaign_events', ['tenant_id' => $tenantB->id, 'visitor_id' => 'v-a']);
    }
}
