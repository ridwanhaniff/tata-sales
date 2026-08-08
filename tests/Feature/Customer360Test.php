<?php

namespace Tests\Feature;

use App\Models\Calculator;
use App\Models\CalculatorSession;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Note;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Customer360Test extends TestCase
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

    public function test_owner_sees_customer_with_leads(): void
    {
        $this->actingAsRole('owner');
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '6281299000011']);
        $product = Product::factory()->for($this->tenant)->create();

        Lead::factory()->for($customer)->create(['product_id' => $product->id, 'status' => 'NEW']);
        Lead::factory()->for($customer)->create(['status' => 'WON', 'estimated_value' => 250000000]);

        $this->getJson('/api/v1/admin/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id)
            ->assertJsonCount(2, 'data.leads')
            ->assertJsonPath('data.leads.0.product.id', $product->id);
    }

    public function test_customer_360_includes_journey_timeline(): void
    {
        $this->actingAsRole('owner');
        $customer = Customer::factory()->for($this->tenant)->create();
        $lead = Lead::factory()->for($customer)->create(['status' => 'NEW']);

        LeadEvent::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'event_type' => 'lead_created',
            'event_data' => ['source' => 'form'],
        ]);
        Note::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'content' => 'Hubungi minggu depan.',
        ]);
        $voucher = Voucher::factory()->for($this->tenant)->create();
        VoucherUsage::create([
            'tenant_id' => $this->tenant->id,
            'voucher_id' => $voucher->id,
            'customer_id' => $customer->id,
            'used_at' => now(),
        ]);
        $calculator = Calculator::factory()->for($this->tenant)->create();
        CalculatorSession::create([
            'tenant_id' => $this->tenant->id,
            'calculator_id' => $calculator->id,
            'customer_id' => $customer->id,
            'lead_id' => $lead->id,
            'input_data' => ['price' => 200000000],
            'output_data' => ['monthly' => 3500000],
        ]);

        $this->getJson('/api/v1/admin/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('data.timeline.0.type', fn ($type) => in_array($type, ['lead_event', 'note', 'voucher', 'calculator'], true))
            ->assertJsonCount(4, 'data.timeline');
    }

    public function test_timeline_is_sorted_by_newest_first(): void
    {
        $this->actingAsRole('owner');
        $customer = Customer::factory()->for($this->tenant)->create();
        $lead = Lead::factory()->for($customer)->create();

        Note::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'content' => 'Catatan terbaru',
            'created_at' => now()->addDay(),
        ]);
        LeadEvent::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'event_type' => 'lead_created',
            'occurred_at' => now()->subDay(),
        ]);

        $this->getJson('/api/v1/admin/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('data.timeline.0.type', 'note')
            ->assertJsonPath('data.timeline.1.type', 'lead_event');
    }

    public function test_manager_can_search_customers(): void
    {
        $this->actingAsRole('manager');
        Customer::factory()->for($this->tenant)->create(['name' => 'Andi Wijaya', 'phone' => '62812990022']);
        Customer::factory()->for($this->tenant)->create(['name' => 'Budi Wijaya', 'phone' => '62812990033']);

        $this->getJson('/api/v1/admin/customers?search=Andi')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Andi Wijaya');

        $this->getJson('/api/v1/admin/customers?search=12990033')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_sales_cannot_access_customer_360(): void
    {
        $this->actingAsRole('sales');
        $customer = Customer::factory()->for($this->tenant)->create();

        $this->getJson('/api/v1/admin/customers/'.$customer->id)
            ->assertStatus(403);
    }

    public function test_customer_from_other_tenant_is_not_visible(): void
    {
        $this->actingAsRole('owner');
        $foreign = Tenant::factory()->create();
        $customer = Customer::factory()->for($foreign)->create();

        $this->getJson('/api/v1/admin/customers/'.$customer->id)
            ->assertStatus(404);
    }
}
