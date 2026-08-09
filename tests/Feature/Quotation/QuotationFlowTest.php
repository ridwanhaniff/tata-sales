<?php

namespace Tests\Feature\Quotation;

use App\Models\Lead;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Quotation\QuotationService;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuotationFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $sales;

    private Lead $lead;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);

        $this->seed([PipelineStageSeeder::class]);

        $this->sales = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'sales']);
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'base_price' => 1_000_000]);

        $this->lead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'QUALIFIED',
            'assigned_to' => $this->sales->id,
            'product_id' => $this->product->id,
            'estimated_value' => 0,
        ]);
    }

    private function items(array $overrides = []): array
    {
        return [[
            'product_id' => $this->product->id,
            'quantity' => 2,
            ...$overrides,
        ]];
    }

    public function test_create_from_lead_transitions_to_proposal(): void
    {
        $quotation = app(QuotationService::class)->createFromLead($this->lead, $this->items());

        $this->assertSame('draft', $quotation->status);
        $this->assertSame(2_000_000.0, (float) $quotation->total);
        $this->assertSame(1, $quotation->items()->count());
        $this->assertSame('PROPOSAL', $this->lead->fresh()->status);
        $this->assertDatabaseHas('lead_events', [
            'lead_id' => $this->lead->id,
            'event_type' => 'quotation_created',
        ]);
    }

    public function test_create_with_discount_and_variant_price(): void
    {
        $quotation = app(QuotationService::class)->createFromLead($this->lead, $this->items([
            'discount' => 100_000,
            'unit_price' => 950_000,
        ]));

        $this->assertSame(1_700_000.0, (float) $quotation->total);
        $this->assertSame(200_000.0, (float) $quotation->discount_total);
    }

    public function test_create_rejects_empty_items(): void
    {
        $this->expectException(ValidationException::class);

        app(QuotationService::class)->createFromLead($this->lead, []);
    }

    public function test_create_rejects_foreign_tenant_product(): void
    {
        $other = Tenant::factory()->create();
        $foreign = Product::factory()->create(['tenant_id' => $other->id, 'base_price' => 500_000]);

        $this->expectException(ValidationException::class);

        app(QuotationService::class)->createFromLead($this->lead, [[
            'product_id' => $foreign->id,
            'quantity' => 1,
        ]]);
    }

    public function test_send_makes_public_token_and_sends_whatsapp(): void
    {
        $quotation = app(QuotationService::class)->createFromLead($this->lead, $this->items());

        $sent = app(QuotationService::class)->send($quotation);

        $this->assertSame('sent', $sent->status);
        $this->assertNotNull($sent->public_token);
        $this->assertNotNull($sent->sent_at);
        $this->assertSame(1, $sent->lead->whatsappMessages()->count());
    }

    public function test_public_show_marks_viewed_and_transitions_negotiation(): void
    {
        $quotation = app(QuotationService::class)->createFromLead($this->lead, $this->items());
        $sent = app(QuotationService::class)->send($quotation);

        $response = $this->getJson('/api/v1/quotes/'.$sent->public_token);

        $response->assertOk()->assertJsonPath('data.status', 'viewed');
        $this->assertSame('viewed', $sent->fresh()->status);
        $this->assertSame('NEGOTIATION', $this->lead->fresh()->status);
        $this->assertDatabaseHas('lead_events', [
            'lead_id' => $this->lead->id,
            'event_type' => 'quotation_viewed',
        ]);
    }

    public function test_accept_marks_won(): void
    {
        $quotation = app(QuotationService::class)->createFromLead($this->lead, $this->items());
        $sent = app(QuotationService::class)->send($quotation);

        $response = $this->postJson("/api/v1/quotes/{$sent->public_token}/respond", [
            'decision' => 'accept',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSame('accepted', $sent->fresh()->status);
        $this->assertSame('WON', $this->lead->fresh()->status);
        $this->assertSame(2_000_000.0, (float) $this->lead->fresh()->estimated_value);
    }

    public function test_reject_marks_lead_lost_with_reason(): void
    {
        $quotation = app(QuotationService::class)->createFromLead($this->lead, $this->items());
        $sent = app(QuotationService::class)->send($quotation);

        $response = $this->postJson("/api/v1/quotes/{$sent->public_token}/respond", [
            'decision' => 'reject',
            'reason' => 'Terlalu mahal',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('LOST', $this->lead->fresh()->status);
        $this->assertDatabaseHas('notes', [
            'lead_id' => $this->lead->id,
            'content' => 'Penolakan quotation: Terlalu mahal',
        ]);
    }

    public function test_bad_token_is_rejected(): void
    {
        $this->getJson('/api/v1/quotes/invalid-token')->assertStatus(422);
        $this->postJson('/api/v1/quotes/invalid-token/respond', ['decision' => 'accept'])->assertStatus(422);
    }

    public function test_expire_overdue_marks_expired_and_lost(): void
    {
        $quotation = app(QuotationService::class)->createFromLead($this->lead, $this->items(), validDays: 1);

        $quotation->forceFill(['valid_until' => now()->subDay()])->save();

        $service = app(QuotationService::class);
        $service->send($quotation);

        $count = $service->expireOverdue();

        $this->assertSame(1, $count);
        $this->assertSame('expired', $quotation->fresh()->status);
        $this->assertSame('LOST', $this->lead->fresh()->status);
    }

    public function test_admin_create_and_send_via_api(): void
    {
        Sanctum::actingAs($this->sales);
        $this->withHeader('X-Tenant-ID', $this->tenant->id);

        $response = $this->postJson('/api/v1/admin/quotes', [
            'lead_id' => $this->lead->id,
            'items' => $this->items(),
        ]);

        $response->assertCreated();
        $quotationId = $response->json('data.id');

        $send = $this->postJson("/api/v1/admin/quotes/{$quotationId}/send");

        $send->assertOk()->assertJsonPath('data.status', 'sent');
    }

    public function test_admin_cannot_destroy_sent_quotation(): void
    {
        Sanctum::actingAs($this->sales);
        $this->withHeader('X-Tenant-ID', $this->tenant->id);

        $quotation = app(QuotationService::class)->createFromLead($this->lead, $this->items());
        app(QuotationService::class)->send($quotation);

        $this->deleteJson("/api/v1/admin/quotes/{$quotation->id}")->assertStatus(422);
        $this->assertNotNull(Quotation::find($quotation->id));
    }
}
