<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentContext;
use App\Agents\CalculatorAgent;
use App\Agents\Support\ToolExecutor;
use App\Agents\Tools\CalculateTool;
use App\Models\AiAgentLog;
use App\Models\Calculator;
use App\Models\CalculatorSession;
use App\Models\Tenant;
use App\Services\Calculator\CalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLLMProvider;
use Tests\TestCase;

class CalculatorAgentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
    }

    private function makeCalculator(): Calculator
    {
        return Calculator::factory()->for($this->tenant)->credit()->create();
    }

    private function agent(FakeLLMProvider $fake): CalculatorAgent
    {
        return new CalculatorAgent($fake, new ToolExecutor);
    }

    private function context(array $meta = []): AgentContext
    {
        return new AgentContext(
            message: 'Berapa cicilan FRONX GLX dengan DP 50 juta tenor 48 bulan?',
            tenant: $this->tenant,
            meta: $meta,
        );
    }

    private function calculatorArgs(Calculator $calculator): array
    {
        return [
            'calculator_id' => $calculator->id,
            'inputs' => ['price' => 249_500_000, 'dp' => 50_000_000, 'tenor' => 48, 'interest' => 8],
        ];
    }

    public function test_tools_are_calculate_and_request_human(): void
    {
        $agent = $this->agent(new FakeLLMProvider);

        $this->assertSame(['calculate', 'request_human'], array_map(fn ($t) => $t->name(), $agent->tools()));
    }

    public function test_agent_runs_calculate_and_replies_with_machine_output(): void
    {
        $calculator = $this->makeCalculator();

        $fake = new FakeLLMProvider([
            FakeLLMProvider::toolCall('calculate', $this->calculatorArgs($calculator)),
            FakeLLMProvider::text('Cicilan Anda sekitar Rp5.000.000 per bulan.'),
        ]);

        $result = $this->agent($fake)->handle($this->context(['calculators' => [
            ['id' => $calculator->id, 'name' => $calculator->name, 'type' => 'credit'],
        ]]));

        $this->assertSame('Cicilan Anda sekitar Rp5.000.000 per bulan.', $result['reply']);
        $this->assertNotNull($result['calculator_result']);
        $this->assertArrayHasKey('monthly_installment', $result['calculator_result']['outputs']);

        // Session tersimpan persis seperti endpoint publik kalkulator
        $session = CalculatorSession::query()->first();
        $this->assertSame($result['calculator_result']['session_id'], $session->id);
        $this->assertSame(249_500_000, $session->input_data['price']);

        // Tool call tercatat dengan status sukses
        $log = AiAgentLog::query()->where('agent', 'calculator')->where('tool_called', 'calculate')->first();
        $this->assertNotNull($log);
        $this->assertSame(AiAgentLog::STATUS_SUCCESS, $log->status);
        $this->assertSame($calculator->id, $log->input['calculator_id']);

        // Hanya dua panggilan LLM: minta tool + jawab final
        $this->assertSame(2, $fake->generateCalls);
    }

    public function test_tool_validation_errors_are_returned_not_fabricated(): void
    {
        $calculator = $this->makeCalculator();

        $tool = new CalculateTool(app(CalculatorService::class));
        $output = $tool->execute(['calculator_id' => $calculator->id, 'inputs' => ['price' => 249_500_000]]);

        $this->assertFalse($output['found']);
        $this->assertArrayHasKey('validation_errors', $output);
        $this->assertArrayHasKey('inputs.dp', $output['validation_errors']);
    }

    public function test_tool_denies_inactive_calculator(): void
    {
        $calculator = $this->makeCalculator();
        $calculator->forceFill(['status' => 'inactive'])->save();

        $tool = new CalculateTool(app(CalculatorService::class));
        $output = $tool->execute(['calculator_id' => $calculator->id, 'inputs' => ['price' => 1]]);

        $this->assertFalse($output['found']);
        $this->assertSame('Kalkulator tidak tersedia untuk percakapan ini.', $output['reason']);
    }

    public function test_tool_denies_foreign_tenant_calculator(): void
    {
        $foreign = Calculator::factory()->for(Tenant::factory()->create())->credit()->create();

        $tool = new CalculateTool(app(CalculatorService::class));
        $output = $tool->execute(['calculator_id' => $foreign->id, 'inputs' => ['price' => 1]]);

        $this->assertFalse($output['found']);
    }

    public function test_agent_without_tool_returns_null_result(): void
    {
        $this->makeCalculator();

        $fake = new FakeLLMProvider([
            FakeLLMProvider::text('Untuk simulasi yang akurat, silakan tanyakan langsung ke tim kami.'),
        ]);

        $result = $this->agent($fake)->handle($this->context(['calculators' => [
            ['id' => $this->tenant->id, 'name' => 'x', 'type' => 'credit'],
        ]]));

        $this->assertNull($result['calculator_result']);
        $this->assertSame(1, $fake->generateCalls);
    }
}
