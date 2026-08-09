<?php

namespace Tests\Feature\Webhook;

use App\Models\Lead;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Lead\LeadService;
use App\Services\Quotation\QuotationService;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundLeadEventsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $sales;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'settings' => [
                'webhook' => [
                    'url' => 'https://crm.example.com/hooks/tata',
                    'secret' => 'out-secret-123',
                ],
            ],
        ]);

        app()->instance('currentTenant', $this->tenant);
        $this->seed([PipelineStageSeeder::class]);

        $this->sales = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'sales']);
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'base_price' => 1_000_000]);
    }

    public function test_expire_overdue_dispatches_quotation_expired_and_loses_lead(): void
    {
        Http::fake();

        $lead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'QUALIFIED',
            'assigned_to' => $this->sales->id,
            'product_id' => $this->product->id,
        ]);

        $quotation = app(QuotationService::class)->createFromLead($lead, [[
            'product_id' => $this->product->id,
            'quantity' => 1,
        ]]);

        $quotation->forceFill([
            'status' => 'sent',
            'valid_until' => now()->subDay(),
        ])->save();

        app(QuotationService::class)->expireOverdue();

        $this->assertSame('expired', $quotation->fresh()->status);
        $this->assertSame('LOST', $lead->fresh()->status);

        Http::assertSent(function ($request) use ($quotation) {
            $payload = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return $payload['event'] === 'quotation.expired'
                && $payload['data']['quotation_id'] === $quotation->id;
        });
    }

    public function test_lead_transition_dispatches_lead_updated(): void
    {
        $lead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'CONTACTED',
            'assigned_to' => $this->sales->id,
            'product_id' => $this->product->id,
        ]);

        Http::fake();

        app(LeadService::class)->transition($lead, 'NURTURE', $this->sales);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return $payload['event'] === 'lead.updated'
                && $payload['data']['from'] === 'CONTACTED'
                && $payload['data']['to'] === 'NURTURE';
        });
    }

    public function test_terminal_transition_fires_only_deal_event(): void
    {
        $lead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'NEGOTIATION',
            'assigned_to' => $this->sales->id,
            'product_id' => $this->product->id,
        ]);

        Http::fake();

        app(LeadService::class)->transition($lead, 'WON', $this->sales);

        $events = collect(Http::recorded())->map(fn ($pair) => json_decode(
            $pair[0]->body(),
            true,
            512,
            JSON_THROW_ON_ERROR
        )['event']);

        $this->assertTrue($events->contains('deal.won'));
        $this->assertFalse($events->contains('lead.updated'));
    }
}
