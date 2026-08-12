<?php

namespace Tests\Feature;

use App\Agents\Contracts\LLMProvider;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Models\WhatsappMessage;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FakeLLMProvider;
use Tests\TestCase;

class WebhookInboundTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $secret = 'rahasia-test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'settings' => ['webhook' => ['inbound_secret' => $this->secret]],
        ]);

        app()->instance('currentTenant', $this->tenant);
        $this->seed([PipelineStageSeeder::class]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function headers(string $providerEventId, array $payload): array
    {
        $body = json_encode($payload);

        return [
            'X-TataSales-Tenant' => $this->tenant->id,
            'X-TataSales-Signature' => hash_hmac('sha256', $body, $this->secret),
            'Content-Type' => 'application/json',
        ];
    }

    private function withFakeLLM(FakeLLMProvider $fake): void
    {
        app()->instance(LLMProvider::class, $fake);
    }

    public function test_signature_mismatch_is_rejected(): void
    {
        $this->postJson('/api/v1/webhooks/whatsapp', [
            'provider_event_id' => Str::uuid(),
            'phone' => '081298765432',
            'message' => 'Halo, ada promo?',
        ], ['X-TataSales-Tenant' => $this->tenant->id, 'X-TataSales-Signature' => 'salah'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_invalid_payload_is_rejected(): void
    {
        $payload = ['provider_event_id' => Str::uuid()];

        $this->postJson('/api/v1/webhooks/whatsapp', $payload, $this->headers('x-1', $payload))
            ->assertStatus(422);
    }

    public function test_valid_webhook_creates_customer_conversation_and_message(): void
    {
        $this->withFakeLLM(new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"availability","confidence":0.95}'),
            FakeLLMProvider::text('Avanza tersedia di showroom kami.'),
        ]));

        $payload = [
            'provider_event_id' => 'wa-msg-0001',
            'phone' => '081298765432',
            'name' => 'Sari',
            'message' => 'Halo, mau tanya harga Avanza.',
            'consent_marketing' => true,
        ];

        $this->postJson('/api/v1/webhooks/whatsapp', $payload, $this->headers('wa-msg-0001', $payload))
            ->assertOk()
            ->assertJsonPath('data.status', 'received');

        // Simpan dulu (received), proses via job
        $this->assertDatabaseHas('webhook_events', [
            'tenant_id' => $this->tenant->id,
            'provider' => 'whatsapp',
            'provider_event_id' => 'wa-msg-0001',
            'status' => 'processed',
        ]);

        $customer = Customer::query()->firstOrFail();
        $this->assertSame('6281298765432', $customer->phone);
        $this->assertTrue($customer->consent_marketing);

        $conversation = Conversation::query()->firstOrFail();
        $this->assertSame('whatsapp', $conversation->channel);
        $this->assertSame(Conversation::STATUS_AI_ACTIVE, $conversation->status);
        $this->assertNotNull($conversation->lead_id);

        // consent diberikan → lead dibuat lewat pipeline penuh
        $lead = Lead::query()->firstOrFail();
        $this->assertSame('NEW', $lead->status);
        $this->assertSame('whatsapp', $lead->source);
        $this->assertSame('6281298765432', $lead->customer->phone);

        // pesan masuk + balasan AI tercatat di conversation
        $this->assertSame(2, $conversation->messages()->count());
        $reply = $conversation->messages()
            ->where('sender_type', ConversationMessage::SENDER_AI)
            ->firstOrFail();
        $this->assertSame('Avanza tersedia di showroom kami.', $reply->content);
        $this->assertSame('availability', $reply->intent);
        $this->assertSame('product', $reply->metadata['agent']);

        // balasan dikirim keluar via outbox WhatsApp (driver echo = sent)
        $sent = WhatsappMessage::query()->firstOrFail();
        $this->assertSame('6281298765432', $sent->to_phone);
        $this->assertSame('Avanza tersedia di showroom kami.', $sent->message);
        $this->assertSame('sent', $sent->status);
        $this->assertSame($lead->id, $sent->lead_id);
        $this->assertSame($conversation->id, $sent->payload['conversation_id']);
        $this->assertSame('ai_reply', $sent->payload['source']);
    }

    public function test_duplicate_event_is_not_processed_twice(): void
    {
        $this->withFakeLLM(new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"unknown","confidence":0.2}'),
            fn () => FakeLLMProvider::toolCall('request_human', [
                'conversation_id' => Conversation::query()->first()->id,
                'reason' => 'pesan tidak jelas',
            ]),
            FakeLLMProvider::text('Baik, saya hubungkan Anda dengan tim kami.'),
        ]));

        $payload = [
            'provider_event_id' => 'wa-dup-0001',
            'phone' => '081298765433',
            'message' => 'Halo',
            'consent_marketing' => true,
        ];

        $headers = $this->headers('wa-dup-0001', $payload);

        $this->postJson('/api/v1/webhooks/whatsapp', $payload, $headers)->assertOk();
        $this->postJson('/api/v1/webhooks/whatsapp', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'duplicate');

        $this->assertSame(1, Customer::count());
        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, WebhookEvent::query()->withoutGlobalScope('tenant')->count());
    }

    public function test_without_consent_no_lead_is_created_but_conversation_is_recorded(): void
    {
        $this->withFakeLLM(new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"availability","confidence":0.95}'),
            FakeLLMProvider::text('Terima kasih sudah menghubungi kami.'),
        ]));

        $payload = [
            'provider_event_id' => 'wa-no-consent',
            'phone' => '081298765434',
            'message' => 'Halo saja',
        ];

        $this->postJson('/api/v1/webhooks/whatsapp', $payload, $this->headers('wa-no-consent', $payload))
            ->assertOk();

        $this->assertSame(1, Customer::count());
        $this->assertSame(0, Lead::count());
        $this->assertSame(1, Conversation::count());
        $this->assertNull(Conversation::firstOrFail()->lead_id);

        // tanpa lead, balasan AI tetap terkirim (lead_id nullable)
        $sent = WhatsappMessage::query()->firstOrFail();
        $this->assertSame('6281298765434', $sent->to_phone);
        $this->assertSame('Terima kasih sudah menghubungi kami.', $sent->message);
        $this->assertSame('sent', $sent->status);
        $this->assertNull($sent->lead_id);
    }

    public function test_second_message_appends_to_existing_conversation(): void
    {
        $this->withFakeLLM(new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"availability","confidence":0.95}'),
            FakeLLMProvider::text('Balasan pertama.'),
            FakeLLMProvider::text('{"intent":"availability","confidence":0.95}'),
            FakeLLMProvider::text('Balasan kedua.'),
        ]));

        $first = [
            'provider_event_id' => 'wa-seq-1',
            'phone' => '081298765435',
            'message' => 'Pertama',
            'consent_marketing' => true,
        ];

        $this->postJson('/api/v1/webhooks/whatsapp', $first, $this->headers('wa-seq-1', $first))->assertOk();

        $second = [
            'provider_event_id' => 'wa-seq-2',
            'phone' => '081298765435',
            'message' => 'Kedua',
        ];

        $this->postJson('/api/v1/webhooks/whatsapp', $second, $this->headers('wa-seq-2', $second))->assertOk();

        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, Lead::count());

        $conversation = Conversation::firstOrFail();
        $this->assertSame(2, $conversation->messages()->where('sender_type', 'customer')->count());
        $this->assertSame(2, $conversation->messages()->where('sender_type', 'ai')->count());
        $this->assertSame(2, WhatsappMessage::count());
    }

    public function test_payment_webhook_is_acknowledged_and_recorded(): void
    {
        $payload = ['provider_event_id' => 'pay-0001', 'amount' => 500000];

        $this->postJson('/api/v1/webhooks/payment', $payload, $this->headers('pay-0001', $payload))
            ->assertOk()
            ->assertJsonPath('data.status', 'received');

        $this->assertDatabaseHas('webhook_events', [
            'tenant_id' => $this->tenant->id,
            'provider' => 'payment',
            'provider_event_id' => 'pay-0001',
            'status' => 'processed',
        ]);
    }

    public function test_unknown_tenant_is_rejected(): void
    {
        $this->postJson('/api/v1/webhooks/whatsapp', [
            'provider_event_id' => 'wa-x',
            'phone' => '081298765436',
            'message' => 'yo',
        ], ['X-TataSales-Tenant' => Str::uuid()])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'UNKNOWN_TENANT');
    }
}
