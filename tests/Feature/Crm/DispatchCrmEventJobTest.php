<?php

namespace Tests\Feature\Crm;

use App\Jobs\DispatchCrmEventJob;
use App\Models\CrmDelivery;
use App\Models\Tenant;
use App\Services\Crm\CrmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DispatchCrmEventJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_marks_delivery_sent_on_success(): void
    {
        config(['crm.driver' => 'http']);

        Http::fake([
            'https://crm.example.com/*' => Http::response(['ok' => true], 201),
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

        $delivery = CrmDelivery::create([
            'tenant_id' => $tenant->id,
            'event' => 'deal.won',
            'provider' => 'http',
            'endpoint' => 'https://crm.example.com/hooks/tata',
            'status' => CrmDelivery::STATUS_PENDING,
            'payload' => ['lead_id' => 'L-1'],
        ]);

        (new DispatchCrmEventJob($delivery))->handle(app(CrmService::class));

        $this->assertSame(CrmDelivery::STATUS_SENT, $delivery->fresh()->status);
        $this->assertSame(201, $delivery->fresh()->http_status);
        $this->assertSame(1, $delivery->fresh()->attempt);
    }

    public function test_job_throws_and_marks_failed_so_retry_happens(): void
    {
        config(['crm.driver' => 'http']);

        Http::fake([
            'https://crm.example.com/*' => Http::response('gone', 410),
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

        $delivery = CrmDelivery::create([
            'tenant_id' => $tenant->id,
            'event' => 'lead.updated',
            'provider' => 'http',
            'endpoint' => 'https://crm.example.com/hooks/tata',
            'status' => CrmDelivery::STATUS_PENDING,
            'payload' => ['lead_id' => 'L-1'],
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            (new DispatchCrmEventJob($delivery))->handle(app(CrmService::class));
        } finally {
            $this->assertSame(CrmDelivery::STATUS_FAILED, $delivery->fresh()->status);
            $this->assertSame(410, $delivery->fresh()->http_status);
        }
    }

    public function test_job_has_retry_backoff(): void
    {
        $delivery = new CrmDelivery([
            'tenant_id' => '00000000-0000-0000-0000-000000000000',
            'event' => 'test.ping',
            'provider' => 'echo',
            'status' => CrmDelivery::STATUS_PENDING,
            'payload' => [],
        ]);

        $job = new DispatchCrmEventJob($delivery);

        $this->assertSame(4, $job->tries);
        $this->assertSame([15, 60, 300, 900], $job->backoff());
    }
}
