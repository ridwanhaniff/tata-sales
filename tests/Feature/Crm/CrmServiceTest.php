<?php

namespace Tests\Feature\Crm;

use App\Models\CrmDelivery;
use App\Models\Tenant;
use App\Services\Crm\CrmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrmServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_creates_delivery_and_syncs_via_echo(): void
    {
        $tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $tenant);

        $delivery = app(CrmService::class)->dispatch($tenant, 'lead.created', [
            'lead_id' => 'L-1',
            'status' => 'NEW',
        ]);

        $this->assertNotNull($delivery);
        $this->assertDatabaseHas('crm_deliveries', [
            'tenant_id' => $tenant->id,
            'event' => 'lead.created',
            'provider' => 'echo',
        ]);

        // QUEUE_CONNECTION=sync → job langsung jalan, delivery jadi sent.
        $this->assertSame(CrmDelivery::STATUS_SENT, $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempt);
        $this->assertSame(200, $delivery->fresh()->http_status);
        $this->assertSame('echo://local', $delivery->fresh()->endpoint);
    }

    public function test_dispatch_skips_when_http_connector_unconfigured(): void
    {
        config(['crm.driver' => 'http']);

        $tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $tenant);

        Http::fake();

        $delivery = app(CrmService::class)->dispatch($tenant, 'lead.created', ['lead_id' => 'L-1']);

        $this->assertNull($delivery);
        $this->assertDatabaseCount('crm_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_dispatch_skips_when_hubspot_unconfigured(): void
    {
        config(['crm.driver' => 'hubspot', 'crm.hubspot.api_key' => '']);

        $tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $tenant);

        $delivery = app(CrmService::class)->dispatch($tenant, 'lead.created', ['lead_id' => 'L-1']);

        $this->assertNull($delivery);
        $this->assertDatabaseCount('crm_deliveries', 0);
    }

    public function test_http_driver_posts_hmac_envelope(): void
    {
        config(['crm.driver' => 'http']);

        Http::fake([
            'https://crm.example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $tenant = Tenant::factory()->create([
            'settings' => [
                'webhook' => [
                    'url' => 'https://crm.example.com/hooks/tata',
                    'secret' => 'out-secret-123',
                ],
            ],
        ]);
        app()->instance('currentTenant', $tenant);

        $delivery = app(CrmService::class)->dispatch($tenant, 'lead.created', [
            'lead_id' => 'L-1',
            'status' => 'NEW',
        ]);

        $this->assertSame(CrmDelivery::STATUS_SENT, $delivery->fresh()->status);
        $this->assertSame(200, $delivery->fresh()->http_status);

        Http::assertSent(function ($request) use ($tenant) {
            $payload = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            $signature = hash_hmac('sha256', $request->body(), 'out-secret-123');

            return $request->url() === 'https://crm.example.com/hooks/tata'
                && $payload['event'] === 'lead.created'
                && $payload['tenant_id'] === $tenant->id
                && $payload['data']['lead_id'] === 'L-1'
                && isset($payload['sent_at'])
                && $request->header('X-TataSales-Signature')[0] === $signature;
        });
    }

    public function test_failed_http_delivery_marks_failed(): void
    {
        config(['crm.driver' => 'http']);

        Http::fake([
            'https://crm.example.com/*' => Http::response('boom', 500),
        ]);

        $tenant = Tenant::factory()->create([
            'settings' => [
                'webhook' => [
                    'url' => 'https://crm.example.com/hooks/tata',
                    'secret' => 's',
                ],
            ],
        ]);
        app()->instance('currentTenant', $tenant);

        try {
            app(CrmService::class)->dispatch($tenant, 'lead.updated', ['lead_id' => 'L-1']);
            $this->fail('Seharusnya konektor melempar exception saat HTTP 500.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('CRM HTTP 500', $e->getMessage());
        }

        $delivery = CrmDelivery::query()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($delivery);
        $this->assertSame(CrmDelivery::STATUS_FAILED, $delivery->fresh()->status);
        $this->assertSame(500, $delivery->fresh()->http_status);
        $this->assertStringContainsString('CRM HTTP 500', (string) $delivery->fresh()->error);
    }
}
