<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttributionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    public function test_utm_and_referrer_are_captured_in_lead_event(): void
    {
        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/leads', [
                'customer' => ['name' => 'Budi', 'phone' => '081298765432'],
                'utm' => ['utm_source' => 'meta', 'utm_medium' => 'paid', 'utm_campaign' => 'fronx-agustus'],
                'landing_page' => '/l/home',
                'referrer' => 'https://facebook.com/ads/123',
                'consent_marketing' => true,
            ])
            ->assertCreated();

        $event = LeadEvent::query()->where('event_type', 'lead_created')->first();
        $this->assertNotNull($event);

        $attribution = $event->event_data['attribution'];
        $this->assertSame('meta', $attribution['utm']['utm_source']);
        $this->assertSame('fronx-agustus', $attribution['utm']['utm_campaign']);
        $this->assertSame('/l/home', $attribution['landing_page']);
        $this->assertSame('https://facebook.com/ads/123', $attribution['referrer']);
    }

    public function test_utm_campaign_matches_active_campaign(): void
    {
        $campaign = Campaign::factory()->for($this->tenant)->create(['utm_campaign' => 'fronx-agustus']);

        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/leads', [
                'customer' => ['name' => 'Budi', 'phone' => '081298765432'],
                'utm' => ['utm_source' => 'meta', 'utm_campaign' => 'fronx-agustus'],
                'consent_marketing' => true,
            ])
            ->assertCreated();

        $lead = Lead::query()->first();
        $this->assertSame($campaign->id, $lead->campaign_id);

        $this->assertDatabaseHas('campaign_events', [
            'tenant_id' => $this->tenant->id,
            'campaign_id' => $campaign->id,
            'event_type' => 'form_complete',
        ]);
    }

    public function test_inactive_campaign_is_not_matched(): void
    {
        Campaign::factory()->for($this->tenant)->create(['utm_campaign' => 'lama', 'status' => 'inactive']);

        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/leads', [
                'customer' => ['name' => 'Budi', 'phone' => '081298765432'],
                'utm' => ['utm_campaign' => 'lama'],
                'consent_marketing' => true,
            ])
            ->assertCreated();

        $lead = Lead::query()->first();
        $this->assertNull($lead->campaign_id);
    }

    public function test_referrer_header_is_used_when_input_absent(): void
    {
        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('Referer', 'https://instagram.com/p/xyz')
            ->postJson('/api/v1/leads', [
                'customer' => ['name' => 'Budi', 'phone' => '081298765432'],
                'consent_marketing' => true,
            ])
            ->assertCreated();

        $event = LeadEvent::query()->where('event_type', 'lead_created')->first();
        $this->assertSame('https://instagram.com/p/xyz', $event->event_data['attribution']['referrer']);
    }

    public function test_customer_without_utm_has_no_attribution_event(): void
    {
        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/leads', [
                'customer' => ['name' => 'Budi', 'phone' => '081298765432'],
                'consent_marketing' => true,
            ])
            ->assertCreated();

        $this->assertDatabaseCount('campaign_events', 0);

        $event = LeadEvent::query()->where('event_type', 'lead_created')->first();
        $this->assertSame([], $event->event_data['attribution']['utm']);
        $this->assertNull($event->event_data['attribution']['referrer']);
    }

    public function test_campaign_is_isolated_per_tenant(): void
    {
        $tenantB = Tenant::factory()->create();
        Campaign::factory()->for($tenantB)->create(['utm_campaign' => 'khusus-b']);
        Customer::factory()->for($tenantB)->create(['phone' => '6281399990001']);

        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/leads', [
                'customer' => ['name' => 'Budi A', 'phone' => '081298765432'],
                'utm' => ['utm_campaign' => 'khusus-b'],
                'consent_marketing' => true,
            ])
            ->assertCreated();

        $lead = Lead::query()->first();
        $this->assertNull($lead->campaign_id);
    }
}
