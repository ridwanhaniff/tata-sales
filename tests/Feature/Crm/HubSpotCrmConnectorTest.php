<?php

namespace Tests\Feature\Crm;

use App\Models\Tenant;
use App\Services\Crm\Exceptions\CrmConnectorException;
use App\Services\Crm\Providers\HubSpotCrmConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubSpotCrmConnectorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        config([
            'crm.hubspot.api_key' => 'pat-123',
            'crm.hubspot.pipeline_id' => 'p1',
            'crm.hubspot.stage_ids' => [
                'won' => 's-won',
                'lost' => 's-lost',
                'new' => 's-new',
            ],
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'lead_id' => 'L-1',
            'status' => 'NEW',
            'customer' => [
                'id' => 'C-1',
                'name' => 'Budi',
                'phone' => '6281234567890',
                'email' => 'budi@example.com',
            ],
            'product' => ['id' => 'P-1', 'name' => 'Fronx', 'base_price' => 180_000_000.0],
            'estimated_value' => 180_000_000.0,
            ...$overrides,
        ];
    }

    public function test_lead_created_creates_contact_and_deal_and_associates(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/search' => Http::response(['results' => []], 200),
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => 'contact-1'], 201),
            'https://api.hubapi.com/crm/v3/objects/deals/search' => Http::response(['results' => []], 200),
            'https://api.hubapi.com/crm/v3/objects/deals' => Http::response(['id' => 'deal-1'], 201),
            'https://api.hubapi.com/crm/v3/objects/deals/*' => Http::response([], 200),
        ]);

        $result = (new HubSpotCrmConnector)->sync($this->tenant, 'lead.created', $this->payload());

        $this->assertSame(200, $result['http_status']);

        Http::assertSent(function ($request) {
            if ($request->url() === 'https://api.hubapi.com/crm/v3/objects/contacts' && $request->method() === 'POST') {
                return $request->header('Authorization')[0] === 'Bearer pat-123'
                    && $request['properties']['email'] === 'budi@example.com'
                    && $request['properties']['firstname'] === 'Budi'
                    && $request['properties']['phone'] === '6281234567890';
            }

            return true;
        });

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.hubapi.com/crm/v3/objects/deals'
                && $request->method() === 'POST'
                && $request['properties']['dealname'] === 'Budi — Fronx'
                && $request['properties']['amount'] === '180000000'
                && $request['properties']['tata_lead_id'] === 'L-1'
                && $request['properties']['pipeline'] === 'p1'
                && $request['properties']['dealstage'] === 's-new';
        });

        Http::assertSent(fn ($request) => $request->url() === 'https://api.hubapi.com/crm/v3/objects/deals/deal-1/associations/contacts/contact-1/4');
    }

    public function test_existing_deal_is_updated_not_duplicated(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/search' => Http::response(['results' => [['id' => 'contact-9', 'properties' => ['email' => 'budi@example.com']]]], 200),
            'https://api.hubapi.com/crm/v3/objects/deals/search' => Http::response(['results' => [['id' => 'deal-9', 'properties' => ['tata_lead_id' => 'L-1']]]], 200),
            'https://api.hubapi.com/crm/v3/objects/deals/deal-9' => Http::response(['id' => 'deal-9'], 200),
            'https://api.hubapi.com/crm/v3/objects/deals/deal-9/associations/*' => Http::response([], 200),
        ]);

        (new HubSpotCrmConnector)->sync($this->tenant, 'deal.won', $this->payload(['status' => 'WON']));

        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.hubapi.com/crm/v3/objects/deals' && $request->method() === 'POST' && ! str_contains($request->url(), 'search'));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.hubapi.com/crm/v3/objects/deals/deal-9'
                && $request->method() === 'PATCH'
                && $request['properties']['dealstage'] === 's-won'
                && $request['properties']['hs_lead_status'] === 'CLOSED_WON';
        });
    }

    public function test_deal_lost_sets_lost_stage(): void
    {
        Http::fake(['*' => Http::response(['id' => 'deal-x'], 200)]);

        (new HubSpotCrmConnector)->sync($this->tenant, 'deal.lost', $this->payload(['status' => 'LOST']));

        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/objects/deals')
            && $request['properties']['hs_lead_status'] === 'CLOSED_LOST');
    }

    public function test_quotation_events_are_ignored_without_http(): void
    {
        Http::fake();

        $result = (new HubSpotCrmConnector)->sync($this->tenant, 'quotation.sent', [
            'quotation_id' => 'Q-1',
        ]);

        $this->assertSame(200, $result['http_status']);
        Http::assertNothingSent();
    }

    public function test_unauthorized_throws_with_http_status(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/search' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        try {
            (new HubSpotCrmConnector)->sync($this->tenant, 'lead.created', $this->payload());

            $this->fail('Seharusnya lempar CrmConnectorException.');
        } catch (CrmConnectorException $e) {
            $this->assertSame(401, $e->httpStatus);
            $this->assertStringContainsString('401', $e->getMessage());
        }
    }

    public function test_missing_api_key_throws_unauthorized_from_hubspot(): void
    {
        config(['crm.hubspot.api_key' => '']);

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/search' => Http::response(['error' => 'auth failed'], 401),
        ]);

        $this->expectException(CrmConnectorException::class);

        (new HubSpotCrmConnector)->sync($this->tenant, 'lead.created', $this->payload());
    }
}
