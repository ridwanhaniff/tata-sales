<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentContext;
use App\Agents\IntentAgent;
use App\Agents\Support\ToolExecutor;
use App\Models\AiAgentLog;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLLMProvider;
use Tests\TestCase;

class IntentAgentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
    }

    private function classify(string $llmJson): array
    {
        $agent = new IntentAgent(
            new FakeLLMProvider([FakeLLMProvider::text($llmJson)]),
            new ToolExecutor,
        );

        return $agent->handle(new AgentContext(
            message: 'Kalau DP 50 juta cicilannya berapa?',
            tenant: $this->tenant,
        ));
    }

    public function test_intent_classifies_installment_with_confidence(): void
    {
        $result = $this->classify('{"intent":"installment","confidence":0.94}');

        $this->assertSame('installment', $result['intent']);
        $this->assertSame(0.94, $result['confidence']);

        $this->assertDatabaseHas('ai_agent_logs', [
            'tenant_id' => $this->tenant->id,
            'agent' => 'intent',
            'tool_called' => null,
            'status' => 'success',
        ]);
        $log = AiAgentLog::query()->where('agent', 'intent')->first();
        $this->assertSame($result['confidence'], (float) $log->confidence);
    }

    public function test_intent_unrecognized_label_falls_back_to_unknown(): void
    {
        // LLM membalas label yang tidak ada di whitelist — agent menolak.
        $result = $this->classify('{"intent":"makan-makan","confidence":0.99}');

        $this->assertSame('unknown', $result['intent']);
        $this->assertSame(0.0, $result['confidence']);
    }

    public function test_intent_garbage_response_falls_back_to_unknown(): void
    {
        $result = $this->classify('Balasan teks tanpa JSON sama sekali');

        $this->assertSame('unknown', $result['intent']);
        $this->assertSame(0.0, $result['confidence']);
    }

    public function test_intent_does_not_expose_tools(): void
    {
        $agent = new IntentAgent(new FakeLLMProvider, new ToolExecutor);

        $this->assertSame('intent', $agent->name());
        $this->assertSame([], $agent->tools());
    }
}
