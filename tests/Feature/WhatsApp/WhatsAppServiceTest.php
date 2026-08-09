<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Followup;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\WhatsappMessage;
use App\Services\FollowUp\FollowUpService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);

        $this->lead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'NEW',
            'assigned_to' => null,
        ]);
    }

    public function test_echo_provider_marks_message_sent(): void
    {
        $record = app(WhatsAppService::class)->send($this->lead, 'Halo, ada penawaran untuk Anda.');

        $this->assertSame('sent', $record->status);
        $this->assertStringStartsWith('echo-', (string) $record->provider_message_id);
        $this->assertSame($this->lead->customer->phone, $record->to_phone);
        $this->assertNotNull($record->sent_at);
    }

    public function test_send_without_customer_phone_throws(): void
    {
        $this->lead->customer->forceFill(['phone' => null])->save();

        $this->expectException(\RuntimeException::class);

        app(WhatsAppService::class)->send($this->lead, 'Pesan tanpa nomor.');
    }

    public function test_meta_provider_posts_to_cloud_api(): void
    {
        config()->set('tata.whatsapp.driver', 'meta');
        config()->set('tata.whatsapp.meta', [
            'token' => 'test-token',
            'phone_number_id' => '123456',
            'graph_version' => 'v22.0',
            'graph_base_url' => 'https://graph.facebook.com',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.XYZ']],
            ], 200),
        ]);

        $record = app(WhatsAppService::class)->send($this->lead, 'Pesan ke Meta.');

        $this->assertSame('sent', $record->status);
        $this->assertSame('wamid.XYZ', $record->provider_message_id);

        Http::assertSent(function (HttpRequest $request) {
            return str_contains($request->url(), '/v22.0/123456/messages')
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['to'] === $this->lead->customer->phone;
        });
    }

    public function test_meta_provider_failure_records_failed(): void
    {
        config()->set('tata.whatsapp.driver', 'meta');
        config()->set('tata.whatsapp.meta', [
            'token' => 'test-token',
            'phone_number_id' => '123456',
            'graph_version' => 'v22.0',
            'graph_base_url' => 'https://graph.facebook.com',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['error' => ['message' => 'invalid token']], 400),
        ]);

        $record = app(WhatsAppService::class)->send($this->lead, 'Gagal kirim.');

        $this->assertSame('failed', $record->status);
        $this->assertNotNull($record->provider_error);
    }

    public function test_meta_provider_unconfigured_marks_failed(): void
    {
        config()->set('tata.whatsapp.driver', 'meta');
        config()->set('tata.whatsapp.meta', ['token' => null, 'phone_number_id' => null]);

        $record = app(WhatsAppService::class)->send($this->lead, 'Belum dikonfigurasi.');

        $this->assertSame('failed', $record->status);
        $this->assertStringContainsString('belum dikonfigurasi', (string) $record->provider_error);
    }

    public function test_followup_send_dispatches_whatsapp_and_sets_sent(): void
    {
        $followup = Followup::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $this->lead->id,
            'status' => 'pending',
            'channel' => 'whatsapp',
            'scheduled_at' => now()->subMinute(),
            'message' => 'Halo {customer_name}, follow-up dari kami.',
        ]);

        $ok = app(FollowUpService::class)->send($followup);

        $this->assertTrue($ok);
        $this->assertSame('sent', $followup->fresh()->status);
        $this->assertSame(1, WhatsappMessage::query()->where('lead_id', $this->lead->id)->count());
        $this->assertSame('sent', WhatsappMessage::query()->first()->status);
    }

    public function test_status_webhook_updates_delivery(): void
    {
        $record = app(WhatsAppService::class)->send($this->lead, 'Pesan untuk cek status.');

        $secret = $this->tenant->settings['webhook']['inbound_secret'] ?? null;

        if (! $secret) {
            $this->tenant->settings = ['webhook' => ['inbound_secret' => 's3cret']];
            $this->tenant->save();
            $secret = 's3cret';
        }

        $body = json_encode([
            'provider_message_id' => $record->provider_message_id,
            'status' => 'delivered',
        ], JSON_THROW_ON_ERROR);

        $response = $this->withHeaders([
            'X-TataSales-Tenant' => $this->tenant->id,
            'X-TataSales-Signature' => hash_hmac('sha256', $body, $secret),
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/webhooks/whatsapp-status', json_decode($body, true, 512, JSON_THROW_ON_ERROR));

        $response->assertOk()->assertJsonPath('data.status', 'delivered');

        $this->assertSame('delivered', $record->fresh()->status);
        $this->assertNotNull($record->fresh()->delivered_at);
    }

    public function test_status_webhook_rejects_bad_signature(): void
    {
        $record = app(WhatsAppService::class)->send($this->lead, 'Pesan cek signature.');

        $response = $this->withHeaders([
            'X-TataSales-Tenant' => $this->tenant->id,
            'X-TataSales-Signature' => 'wrong',
        ])->postJson('/api/v1/webhooks/whatsapp-status', [
            'provider_message_id' => $record->provider_message_id,
            'status' => 'read',
        ]);

        $response->assertStatus(401);

        $this->assertSame('sent', $record->fresh()->status);
    }

    public function test_status_webhook_unknown_message_returns_404(): void
    {
        $this->tenant->settings = ['webhook' => ['inbound_secret' => 's3cret']];
        $this->tenant->save();

        $secret = 's3cret';
        $body = json_encode([
            'provider_message_id' => 'unknown-id',
            'status' => 'delivered',
        ], JSON_THROW_ON_ERROR);

        $response = $this->withHeaders([
            'X-TataSales-Tenant' => $this->tenant->id,
            'X-TataSales-Signature' => hash_hmac('sha256', $body, $secret),
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/webhooks/whatsapp-status', json_decode($body, true, 512, JSON_THROW_ON_ERROR));

        $response->assertStatus(404);
    }
}
