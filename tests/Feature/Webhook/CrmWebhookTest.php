<?php

namespace Tests\Feature\Webhook;

use App\Jobs\ProcessCrmWebhookJob;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Services\Lead\LeadService;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrmWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->secret = 'crm-secret-123';

        $this->tenant = Tenant::factory()->create([
            'settings' => ['webhook' => ['inbound_secret' => $this->secret]],
        ]);

        app()->instance('currentTenant', $this->tenant);
        $this->seed([PipelineStageSeeder::class]);
    }

    public function test_crm_webhook_dispatches_processing_job(): void
    {
        Queue::fake();

        $payload = [
            'provider_event_id' => 'crm-ev-1',
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
            'consent_marketing' => true,
        ];

        $this->postJson('/api/v1/webhooks/crm', $payload, $this->signedHeaders($payload))
            ->assertOk()
            ->assertJsonPath('data.status', 'received');

        $this->assertDatabaseHas('webhook_events', [
            'provider' => 'crm',
            'provider_event_id' => 'crm-ev-1',
        ]);

        Queue::assertPushed(ProcessCrmWebhookJob::class);
    }

    public function test_crm_webhook_requires_valid_signature(): void
    {
        $payload = ['provider_event_id' => 'crm-ev-2', 'phone' => '081234567891'];

        $this->postJson('/api/v1/webhooks/crm', $payload, [
            'X-TataSales-Tenant' => $this->tenant->id,
            'X-TataSales-Signature' => 'wrong-signature',
        ])->assertStatus(401);
    }

    public function test_crm_webhook_duplicate_event_is_idempotent(): void
    {
        WebhookEvent::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'crm',
            'provider_event_id' => 'crm-ev-dup',
            'payload' => ['x' => 1],
            'status' => WebhookEvent::STATUS_RECEIVED,
        ]);

        $payload = ['provider_event_id' => 'crm-ev-dup', 'phone' => '081299999999'];

        $this->postJson('/api/v1/webhooks/crm', $payload, $this->signedHeaders($payload))
            ->assertOk()
            ->assertJsonPath('data.status', 'duplicate');
    }

    public function test_crm_job_creates_customer_and_lead_with_note(): void
    {
        $event = $this->receivedEvent('crm-ev-3', [
            'provider_event_id' => 'crm-ev-3',
            'name' => 'Siti Aminah',
            'phone' => '081298765432',
            'email' => 'siti@example.com',
            'consent_marketing' => true,
            'notes' => 'Minat dari pameran',
        ]);

        (new ProcessCrmWebhookJob($event))->handle(app(LeadService::class));

        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->fresh()->status);

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $this->tenant->id,
            'phone' => '6281298765432',
            'source' => 'crm',
        ]);

        $this->assertDatabaseHas('leads', [
            'tenant_id' => $this->tenant->id,
            'source' => 'crm',
        ]);

        $this->assertDatabaseHas('notes', [
            'tenant_id' => $this->tenant->id,
            'content' => 'Minat dari pameran',
        ]);
    }

    public function test_crm_job_without_consent_records_customer_only(): void
    {
        $event = $this->receivedEvent('crm-ev-4', [
            'provider_event_id' => 'crm-ev-4',
            'name' => 'Tanpa Consent',
            'phone' => '081211111111',
        ]);

        (new ProcessCrmWebhookJob($event))->handle(app(LeadService::class));

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $this->tenant->id,
            'phone' => '6281211111111',
        ]);

        $this->assertDatabaseMissing('leads', [
            'tenant_id' => $this->tenant->id,
            'source' => 'crm',
        ]);
    }

    public function test_crm_job_duplicate_provider_event_maps_to_same_lead(): void
    {
        $event = $this->receivedEvent('crm-ev-5', [
            'provider_event_id' => 'crm-ev-5',
            'name' => 'Duplikat Test',
            'phone' => '081255555555',
            'consent_marketing' => true,
        ]);

        (new ProcessCrmWebhookJob($event))->handle(app(LeadService::class));

        $count = Lead::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('source', 'crm')
            ->count();

        $this->assertSame(1, $count);
    }

    private function receivedEvent(string $eventId, array $payload): WebhookEvent
    {
        return WebhookEvent::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'crm',
            'provider_event_id' => $eventId,
            'payload' => $payload,
            'status' => WebhookEvent::STATUS_RECEIVED,
        ]);
    }

    private function signedHeaders(array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return [
            'X-TataSales-Tenant' => $this->tenant->id,
            'X-TataSales-Signature' => hash_hmac('sha256', $body, $this->secret),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }
}
