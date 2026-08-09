<?php

namespace Tests\Feature\Webhook;

use App\Jobs\DispatchOutboundWebhookJob;
use App\Models\Tenant;
use App\Services\Webhook\OutboundWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_queues_job_when_webhook_configured(): void
    {
        $tenant = Tenant::factory()->create([
            'settings' => [
                'webhook' => [
                    'url' => 'https://crm.example.com/hooks/tata',
                    'secret' => 'out-secret-123',
                ],
            ],
        ]);

        Http::fake();

        (new OutboundWebhookService)->dispatch($tenant, 'lead.created', ['lead_id' => 'L-1']);

        Http::assertSent(function ($request) use ($tenant) {
            $payload = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            $signature = hash_hmac(
                'sha256',
                $request->body(),
                'out-secret-123'
            );

            return $request->url() === 'https://crm.example.com/hooks/tata'
                && $payload['event'] === 'lead.created'
                && $payload['tenant_id'] === $tenant->id
                && $payload['data'] === ['lead_id' => 'L-1']
                && $request->header('X-TataSales-Signature')[0] === $signature;
        });
    }

    public function test_dispatch_returns_false_without_webhook_url(): void
    {
        $tenant = Tenant::factory()->create();

        $dispatched = (new OutboundWebhookService)->dispatch($tenant, 'lead.created', []);

        $this->assertFalse($dispatched);
        Http::assertNothingSent();
    }

    public function test_dispatch_sends_event_to_configured_url(): void
    {
        $tenant = Tenant::factory()->create([
            'settings' => [
                'webhook' => [
                    'url' => 'https://webhook.site/tata-sales',
                ],
            ],
        ]);

        Http::fake([
            'https://webhook.site/*' => Http::response(['ok' => true], 200),
        ]);

        (new OutboundWebhookService)->dispatch($tenant, 'quotation.sent', [
            'quotation_id' => 'Q-1',
        ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://webhook.site/tata-sales');
    }

    public function test_dispatch_job_retries_on_failure(): void
    {
        $tenant = Tenant::factory()->create([
            'settings' => [
                'webhook' => [
                    'url' => 'https://webhook.site/fail',
                    'secret' => 'out-secret-123',
                ],
            ],
        ]);

        Http::fake([
            'https://webhook.site/fail' => Http::response(null, 500),
        ]);

        $job = new DispatchOutboundWebhookJob(
            tenantId: $tenant->id,
            event: 'lead.updated',
            data: ['lead_id' => 'L2'],
            secret: 'out-secret-123',
            url: 'https://webhook.site/fail',
        );

        $this->expectException(\RuntimeException::class);
        $job->handle();
    }
}
