<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Lead\LeadService;
use App\Services\Notification\NotificationService;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookOutboundTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $webhookUrl = 'https://crm.example.com/webhook';

    private string $outboundSecret = 'out-secret';

    protected function setUp(): void
    {
        parent::setUp();

        // Event keluar lewat konektor CRM (§78) — paksa driver http.
        config(['crm.driver' => 'http']);

        $this->tenant = Tenant::factory()->create([
            'settings' => ['webhook' => [
                'url' => $this->webhookUrl,
                'secret' => $this->outboundSecret,
            ]],
        ]);

        app()->instance('currentTenant', $this->tenant);
        $this->seed([PipelineStageSeeder::class]);
    }

    public function test_lead_created_dispatches_signed_webhook(): void
    {
        Http::fake([
            $this->webhookUrl => Http::response(['ok' => true], 200),
        ]);

        $result = app(LeadService::class)->createFromForm([
            'customer' => ['name' => 'Budi', 'phone' => '081298765432'],
            'source' => 'form',
            'consent_marketing' => true,
            'skip_assignment' => true,
        ], $this->tenant, new Request);

        Http::assertSent(function ($request) {
            if ($request->url() !== $this->webhookUrl) {
                return false;
            }

            $body = json_decode($request->body(), true);

            $expectedSignature = hash_hmac('sha256', $request->body(), $this->outboundSecret);

            return $body['event'] === 'lead.created'
                && $request->hasHeader('X-TataSales-Signature', $expectedSignature);
        });
    }

    public function test_tenant_without_webhook_config_sends_nothing(): void
    {
        $this->tenant->forceFill(['settings' => []])->save();

        Http::fake();

        app(LeadService::class)->createFromForm([
            'customer' => ['name' => 'Budi', 'phone' => '081298765431'],
            'consent_marketing' => true,
            'skip_assignment' => true,
        ], $this->tenant);

        Http::assertNothingSent();
    }

    public function test_deal_won_dispatches_webhook(): void
    {
        Http::fake([$this->webhookUrl => Http::response('ok', 200)]);

        $sales = User::factory()->for($this->tenant)->role('sales')->create();
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '6281212345678']);
        $lead = Lead::factory()->for($customer)->create([
            'status' => 'NEGOTIATION',
            'assigned_to' => $sales->id,
        ]);

        app(LeadService::class)->transition($lead, 'WON');

        Http::assertSent(fn ($r) => $r->url() === $this->webhookUrl
            && json_decode($r->body(), true)['event'] === 'deal.won');
    }

    public function test_notification_webhook_channel_forwards_to_tenant_webhook(): void
    {
        Http::fake([$this->webhookUrl => Http::response('ok', 200)]);

        $sales = User::factory()->for($this->tenant)->role('sales')->create();

        $notification = app(NotificationService::class)->notify(
            $this->tenant->id,
            $sales->id,
            'alert',
            'Peringatan stok',
            'Stok menipis.',
            ['product_id' => 'x'],
            'webhook'
        );

        $this->assertNotNull($notification->fresh()->sent_at);
        $this->assertSame('webhook', $notification->channel);

        Http::assertSent(function ($request) {
            return $request->url() === $this->webhookUrl
                && json_decode($request->body(), true)['event'] === 'notification.sent';
        });
    }

    public function test_notification_whatsapp_channel_is_queued_until_provider_ready(): void
    {
        $sales = User::factory()->for($this->tenant)->role('sales')->create();

        $notification = app(NotificationService::class)->notify(
            $this->tenant->id,
            $sales->id,
            'followup_sent',
            'Follow-up terkirim',
            'WA tidak dikirim dulu di MVP.',
            channel: 'whatsapp'
        );

        $this->assertNull($notification->fresh()->sent_at);
        $this->assertSame('queued', $notification->fresh()->data['delivery']);
    }
}
