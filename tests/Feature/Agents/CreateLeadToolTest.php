<?php

namespace Tests\Feature\Agents;

use App\Agents\Tools\CreateLeadTool;
use App\Models\Calculator;
use App\Models\CalculatorSession;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Services\Lead\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateLeadToolTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
    }

    private function makeProduct(): Product
    {
        $category = ProductCategory::factory()->for($this->tenant)->create(['name' => 'SUV']);

        return Product::factory()->for($this->tenant)->create([
            'name' => 'FRONX GLX',
            'base_price' => 249_500_000,
            'published_at' => now(),
            'category_id' => $category->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function conversation(Customer $customer, array $attributes = []): Conversation
    {
        return Conversation::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'channel' => 'webchat',
            'status' => Conversation::STATUS_AI_ACTIVE,
            'context' => [],
            ...$attributes,
        ]);
    }

    private function bind(Conversation $conversation): void
    {
        app()->instance('currentConversation', $conversation);
    }

    private function tool(): CreateLeadTool
    {
        return new CreateLeadTool(app(LeadService::class));
    }

    public function test_creates_lead_with_consent_and_links_conversation(): void
    {
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '6281299999999', 'consent_marketing' => true]);
        $product = $this->makeProduct();
        $conversation = $this->conversation($customer);
        $this->bind($conversation);

        $result = $this->tool()->execute(['product_id' => $product->id]);

        $this->assertTrue($result['done']);

        $lead = Lead::query()->firstOrFail();
        $this->assertSame($lead->id, $result['lead_id']);
        $this->assertSame($lead->id, $conversation->fresh()->lead_id);
        $this->assertSame('chat', $lead->source);
        $this->assertSame('NEW', $lead->status);
        $this->assertSame($customer->id, $lead->customer_id);
        $this->assertSame($product->id, $lead->product_id);
        $this->assertNull($lead->assigned_to);

        // pipeline penuh: lead_created tercatat; assignment sengaja tidak
        // dilakukan di sini (langkah berikutnya di chain: assign_sales).
        $this->assertSame(1, LeadEvent::where('lead_id', $lead->id)->where('event_type', 'lead_created')->count());
        $this->assertSame(0, LeadEvent::where('lead_id', $lead->id)->where('event_type', 'sales_assigned')->count());
    }

    public function test_refuses_without_consent(): void
    {
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '6281299999998', 'consent_marketing' => false]);
        $this->bind($this->conversation($customer));

        $result = $this->tool()->execute([]);

        $this->assertFalse($result['done']);
        $this->assertStringContainsString('consent', $result['reason']);
        $this->assertSame(0, Lead::count());
    }

    public function test_refuses_when_conversation_already_has_lead(): void
    {
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '6281299999997', 'consent_marketing' => true]);
        $lead = Lead::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id]);
        $this->bind($this->conversation($customer, ['lead_id' => $lead->id]));

        $result = $this->tool()->execute([]);

        $this->assertFalse($result['done']);
        $this->assertStringContainsString('sudah memiliki lead', $result['reason']);
        $this->assertSame(1, Lead::count());
    }

    public function test_refuses_without_conversation_context(): void
    {
        $result = $this->tool()->execute([]);

        $this->assertFalse($result['done']);
        $this->assertStringContainsString('konteks percakapan', $result['reason']);
        $this->assertSame(0, Lead::count());
    }

    public function test_refuses_unpublished_or_foreign_product(): void
    {
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '6281299999996', 'consent_marketing' => true]);
        $otherTenant = Tenant::factory()->create();
        $foreign = Product::factory()->for($otherTenant)->create(['status' => 'published', 'published_at' => now()]);
        $unpublished = $this->makeProduct();
        $unpublished->forceFill(['status' => 'draft'])->save();
        $this->bind($this->conversation($customer));

        $this->assertFalse($this->tool()->execute(['product_id' => $foreign->id])['done']);
        $this->assertFalse($this->tool()->execute(['product_id' => $unpublished->id])['done']);
        $this->assertSame(0, Lead::count());
    }

    public function test_links_valid_calculator_session_and_records_completed_event(): void
    {
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '6281299999995', 'consent_marketing' => true]);
        $this->bind($this->conversation($customer));

        $calculator = Calculator::factory()->for($this->tenant)->credit()->create();
        $session = CalculatorSession::create([
            'tenant_id' => $this->tenant->id,
            'calculator_id' => $calculator->id,
            'input_data' => [],
            'output_data' => [],
        ]);

        $result = $this->tool()->execute(['calculator_session_id' => $session->id]);

        $this->assertTrue($result['done']);

        $lead = Lead::query()->firstOrFail();
        $this->assertSame($lead->id, $session->fresh()->lead_id);
        $this->assertSame(1, LeadEvent::where('lead_id', $lead->id)->where('event_type', 'calculator_completed')->count());
    }

    public function test_refuses_foreign_calculator_session(): void
    {
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '6281299999994', 'consent_marketing' => true]);
        $this->bind($this->conversation($customer));

        $otherTenant = Tenant::factory()->create();
        $calculator = Calculator::factory()->for($otherTenant)->credit()->create();
        $session = CalculatorSession::create([
            'tenant_id' => $otherTenant->id,
            'calculator_id' => $calculator->id,
            'input_data' => [],
            'output_data' => [],
        ]);

        $result = $this->tool()->execute(['calculator_session_id' => $session->id]);

        $this->assertFalse($result['done']);
        $this->assertStringContainsString('Sesi kalkulator', $result['reason']);
        $this->assertSame(0, Lead::count());
    }
}
