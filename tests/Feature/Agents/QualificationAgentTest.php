<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentContext;
use App\Agents\QualificationAgent;
use App\Agents\Support\ToolExecutor;
use App\Agents\Tools\UpdateLeadTool;
use App\Models\AiAgentLog;
use App\Models\Lead;
use App\Models\Note;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Services\Lead\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLLMProvider;
use Tests\TestCase;

class QualificationAgentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
    }

    private function makeLead(): Lead
    {
        return Lead::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'QUALIFIED']);
    }

    private function makeProduct(): Product
    {
        $category = ProductCategory::factory()->for($this->tenant)->create(['name' => 'SUV']);
        $product = Product::factory()->for($this->tenant)->create([
            'name' => 'FRONX GLX',
            'base_price' => 249_500_000,
            'published_at' => now(),
            'category_id' => $category->id,
        ]);
        $product->attributes()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_key' => 'cc',
            'attribute_value' => '1500',
            'attribute_type' => 'number',
        ]);

        return $product;
    }

    private function agent(FakeLLMProvider $fake): QualificationAgent
    {
        return new QualificationAgent($fake, new ToolExecutor);
    }

    private function context(Lead $lead, string $message = 'Saya mau DP 100 juta dan tidak ingin rumah lebih dari 3 bulan lagi.'): AgentContext
    {
        return new AgentContext(
            message: $message,
            tenant: $this->tenant,
            leadId: $lead->id,
        );
    }

    public function test_tools_are_update_lead_and_request_human(): void
    {
        $agent = $this->agent(new FakeLLMProvider);

        $this->assertSame(['update_lead', 'request_human'], array_map(fn ($t) => $t->name(), $agent->tools()));
    }

    public function test_agent_updates_qualified_fields_only(): void
    {
        $lead = $this->makeLead();
        $product = $this->makeProduct();

        $fake = new FakeLLMProvider([
            FakeLLMProvider::toolCall('update_lead', [
                'lead_id' => $lead->id,
                'fields' => [
                    'estimated_value' => 150_000_000,
                    'product_id' => $product->id,
                    'customer_location' => 'Jakarta Selatan',
                    'timeline' => 'dalam 3 bulan',
                ],
            ]),
            FakeLLMProvider::text('Baik, saya catat budget Anda dan preferensi lokasi.'),
        ]);

        $result = $this->agent($fake)->handle($this->context($lead));

        $this->assertStringContainsString('Baik', $result['reply']);

        $lead->refresh();
        $this->assertSame('150000000.00', $lead->estimated_value);
        $this->assertSame($product->id, $lead->product_id);
        $this->assertSame('Jakarta Selatan', $lead->customer->location);

        $note = Note::where('lead_id', $lead->id)->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('dalam 3 bulan', $note->content);
        $this->assertSame(1, $lead->events()->where('event_type', 'qualification_updated')->count());

        $this->assertSame(['estimated_value', 'product_id', 'customer_location', 'timeline'], array_keys($result['updated_fields']));

        $log = AiAgentLog::query()->where('agent', 'qualification')->where('tool_called', 'update_lead')->first();
        $this->assertNotNull($log);
        $this->assertSame(AiAgentLog::STATUS_SUCCESS, $log->status);
        $this->assertSame($lead->id, $log->lead_id);

        $this->assertSame(2, $fake->generateCalls);
    }

    public function test_agent_does_not_record_unspoken_values(): void
    {
        $lead = $this->makeLead();

        $fake = new FakeLLMProvider([
            FakeLLMProvider::toolCall('update_lead', [
                'lead_id' => $lead->id,
                'fields' => ['estimated_value' => 99_000_000], // hanya budget yang diucapkan
            ]),
            FakeLLMProvider::text('Saya catat budget Anda.'),
        ]);

        $this->agent($fake)->handle($this->context($lead, 'Budget saya 99 juta.'));

        $lead->refresh();
        $this->assertSame('99000000.00', $lead->estimated_value);
        $this->assertNull($lead->product_id);
        $this->assertNull($lead->customer->location);
    }

    public function test_guardrail_rejects_invalid_estimated_value(): void
    {
        $lead = $this->makeLead();

        $tool = new UpdateLeadTool(app(LeadService::class));
        $output = $tool->execute([
            'lead_id' => $lead->id,
            'fields' => ['estimated_value' => 'murah banget'],
        ]);

        $this->assertFalse($output['done']);
        $lead->refresh();
        $this->assertNull($lead->estimated_value);
        $this->assertSame(0, $lead->events()->where('event_type', 'qualification_updated')->count());
    }

    public function test_guardrail_denies_foreign_tenant_lead(): void
    {
        $foreignTenant = Tenant::factory()->create();
        $foreignLead = Lead::factory()->create(['tenant_id' => $foreignTenant->id]);

        $tool = new UpdateLeadTool(app(LeadService::class));
        $output = $tool->execute([
            'lead_id' => $foreignLead->id,
            'fields' => ['estimated_value' => 10_000_000],
        ]);

        $this->assertFalse($output['done']);
        $foreignLead->refresh();
        $this->assertNull($foreignLead->estimated_value);
    }

    public function test_agent_without_tool_returns_null_updated_fields(): void
    {
        $lead = $this->makeLead();

        $fake = new FakeLLMProvider([
            FakeLLMProvider::text('Baik, terima kasih atas informasinya.'),
        ]);

        $result = $this->agent($fake)->handle($this->context($lead));

        $this->assertNull($result['updated_fields']);
        $this->assertSame(1, $fake->generateCalls);
    }
}
