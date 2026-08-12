<?php

namespace Tests\Feature\Crm;

use App\Models\CrmDelivery;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmWebhookSettingTest extends TestCase
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

    public function test_show_returns_webhook_settings_without_secret_value(): void
    {
        $this->actingAsRole('owner');

        $this->tenant->forceFill([
            'settings' => [
                'webhook' => [
                    'url' => 'https://crm.example.com/hooks/tata',
                    'secret' => 'out-secret-123',
                    'inbound_secret' => 'in-secret-456',
                ],
            ],
        ])->save();

        $this->getJson('/api/v1/admin/settings/webhook')
            ->assertOk()
            ->assertJsonPath('data.url', 'https://crm.example.com/hooks/tata')
            ->assertJsonPath('data.secret_configured', true)
            ->assertJsonPath('data.inbound_secret_configured', true)
            ->assertJsonPath('data.driver', 'echo')
            ->assertJsonMissing(['data.secret' => 'out-secret-123']);
    }

    public function test_update_persists_webhook_settings(): void
    {
        $this->actingAsRole('manager');

        $this->putJson('/api/v1/admin/settings/webhook', [
            'url' => 'https://crm.example.com/hooks/tata',
            'secret' => 'new-secret',
            'inbound_secret' => 'new-in-secret',
        ])->assertOk();

        $this->assertSame('https://crm.example.com/hooks/tata', $this->tenant->fresh()->settings['webhook']['url']);
        $this->assertSame('new-secret', $this->tenant->fresh()->settings['webhook']['secret']);
        $this->assertSame('new-in-secret', $this->tenant->fresh()->settings['webhook']['inbound_secret']);
    }

    public function test_update_requires_valid_url(): void
    {
        $this->actingAsRole('owner');

        $this->putJson('/api/v1/admin/settings/webhook', [
            'url' => 'not-a-url',
        ])->assertStatus(422);
    }

    public function test_sales_role_forbidden(): void
    {
        $this->actingAsRole('sales');

        $this->getJson('/api/v1/admin/settings/webhook')->assertForbidden();
        $this->putJson('/api/v1/admin/settings/webhook', ['url' => 'https://x.example.com/h'])->assertForbidden();
        $this->postJson('/api/v1/admin/settings/webhook/test')->assertForbidden();
    }

    public function test_test_ping_with_echo_driver_returns_sent(): void
    {
        $this->actingAsRole('owner');

        config(['crm.driver' => 'echo']);

        $this->postJson('/api/v1/admin/settings/webhook/test')
            ->assertOk()
            ->assertJsonPath('data.delivery.status', 'sent')
            ->assertJsonPath('data.delivery.http_status', 200);

        $this->assertDatabaseHas('crm_deliveries', [
            'tenant_id' => $this->tenant->id,
            'event' => 'test.ping',
            'status' => 'sent',
        ]);
    }

    public function test_test_ping_without_http_config_returns_422(): void
    {
        $this->actingAsRole('owner');

        config(['crm.driver' => 'http']);

        $this->postJson('/api/v1/admin/settings/webhook/test')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CRM_NOT_CONFIGURED');

        $this->assertDatabaseCount('crm_deliveries', 0);
    }

    public function test_delivery_log_lists_and_filters(): void
    {
        $this->actingAsRole('owner');

        CrmDelivery::create([
            'tenant_id' => $this->tenant->id,
            'event' => 'lead.created',
            'provider' => 'echo',
            'endpoint' => 'echo://local',
            'status' => CrmDelivery::STATUS_SENT,
            'payload' => ['lead_id' => 'L-1'],
            'http_status' => 200,
        ]);
        CrmDelivery::create([
            'tenant_id' => $this->tenant->id,
            'event' => 'deal.won',
            'provider' => 'http',
            'endpoint' => 'https://crm.example.com/x',
            'status' => CrmDelivery::STATUS_FAILED,
            'payload' => ['lead_id' => 'L-2'],
            'error' => 'CRM HTTP 500',
        ]);

        $this->getJson('/api/v1/admin/crm/deliveries')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/admin/crm/deliveries?status=failed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.event', 'deal.won')
            ->assertJsonPath('data.0.error', 'CRM HTTP 500')
            ->assertJsonPath('data.0.payload', null);

        $this->getJson('/api/v1/admin/crm/deliveries?event=lead.created')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.event', 'lead.created');

        $deliveryId = CrmDelivery::query()->where('event', 'deal.won')->first()->id;

        $this->getJson("/api/v1/admin/crm/deliveries/{$deliveryId}")
            ->assertOk()
            ->assertJsonPath('data.id', $deliveryId);
    }
}
