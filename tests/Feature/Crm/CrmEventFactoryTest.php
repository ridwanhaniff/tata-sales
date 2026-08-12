<?php

namespace Tests\Feature\Crm;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Crm\CrmEventFactory;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmEventFactoryTest extends TestCase
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

    private function lead(array $overrides = []): Lead
    {
        return Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'NEW',
            'score' => 42,
            'source' => 'landing',
            'estimated_value' => 150_000_000,
            ...$overrides,
        ]);
    }

    public function test_lead_payload_has_canonical_shape(): void
    {
        $customer = Customer::factory()->create([
            'name' => 'Budi',
            'phone' => '081234567890',
            'email' => 'budi@example.com',
        ]);
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Fronx', 'base_price' => 180_000_000]);
        $campaign = Campaign::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Launch']);
        $sales = User::factory()->for($this->tenant)->create(['role' => 'sales', 'name' => 'Adi Sales']);

        $lead = $this->lead([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'campaign_id' => $campaign->id,
            'assigned_to' => $sales->id,
        ]);

        $payload = (new CrmEventFactory)->lead('lead.created', $lead->refresh()->load(['customer', 'product', 'campaign', 'assignedUser']));

        $this->assertSame($lead->id, $payload['lead_id']);
        $this->assertSame('NEW', $payload['status']);
        $this->assertSame(['key' => 'NEW', 'name' => 'New'], $payload['pipeline_stage']);
        $this->assertSame(42, $payload['score']);
        $this->assertSame(150_000_000.0, $payload['estimated_value']);
        $this->assertSame($customer->id, $payload['customer']['id']);
        $this->assertSame('Budi', $payload['customer']['name']);
        $this->assertSame($product->id, $payload['product']['id']);
        $this->assertSame('Fronx', $payload['product']['name']);
        $this->assertSame($campaign->id, $payload['campaign']['id']);
        $this->assertSame('Launch', $payload['campaign']['name']);
        $this->assertSame($sales->id, $payload['assigned_sales']['id']);
        $this->assertArrayHasKey('occurred_at', $payload);
    }

    public function test_lead_updated_merges_extra_fields(): void
    {
        $lead = $this->lead();

        $payload = (new CrmEventFactory)->lead('lead.updated', $lead, [
            'from' => 'NEW',
            'to' => 'CONTACTED',
        ]);

        $this->assertSame('NEW', $payload['from']);
        $this->assertSame('CONTACTED', $payload['to']);
    }

    public function test_lead_payload_null_relations_safely(): void
    {
        $lead = $this->lead();

        $payload = (new CrmEventFactory)->lead('lead.created', $lead);

        $this->assertNull($payload['product']);
        $this->assertNull($payload['campaign']);
        $this->assertNull($payload['assigned_sales']);
    }

    public function test_quotation_payload_includes_customer_items_and_totals(): void
    {
        $customer = Customer::factory()->create(['name' => 'Siti']);
        $lead = $this->lead(['customer_id' => $customer->id, 'status' => 'PROPOSAL']);

        $quotation = Quotation::factory()->forLead($lead)->create([
            'status' => 'sent',
            'subtotal' => 10_000,
            'discount_total' => 1_000,
            'total' => 9_000,
            'sent_at' => now(),
        ]);

        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'tenant_id' => $this->tenant->id,
            'description' => 'Suku cadang',
            'quantity' => 2,
            'unit_price' => 5_000,
            'discount' => 0,
            'line_total' => 10_000,
        ]);

        $payload = (new CrmEventFactory)->quotation('quotation.sent', $quotation->fresh(['customer']));

        $this->assertSame($quotation->id, $payload['quotation_id']);
        $this->assertSame('sent', $payload['status']);
        $this->assertSame($lead->id, $payload['lead_id']);
        $this->assertSame('Siti', $payload['customer']['name']);
        $this->assertSame(9_000.0, $payload['total']);
        $this->assertCount(1, $payload['items']);
        $this->assertSame('Suku cadang', $payload['items'][0]['name']);
        $this->assertSame(2, $payload['items'][0]['quantity']);
        $this->assertArrayHasKey('occurred_at', $payload);
    }
}
