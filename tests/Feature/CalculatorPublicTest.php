<?php

namespace Tests\Feature;

use App\Models\Calculator;
use App\Models\CalculatorSession;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CalculatorPublicTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function makeCreditCalculator(): Calculator
    {
        return Calculator::factory()->for($this->tenant)->credit()->create();
    }

    private function postWithTenant(string $uri, array $payload = []): TestResponse
    {
        return $this->withHeader('X-Tenant-ID', $this->tenant->id)->postJson($uri, $payload);
    }

    public function test_calculate_returns_outputs_and_stores_session(): void
    {
        $calculator = $this->makeCreditCalculator();

        $response = $this->postWithTenant('/api/v1/calculators/'.$calculator->id.'/calculate', [
            'inputs' => ['price' => 249500000, 'dp' => 50000000, 'tenor' => 60, 'interest' => 6.5],
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['session_id', 'outputs' => ['monthly_installment', 'total_payment']]]);

        $sessionId = $response->json('data.session_id');
        $this->assertDatabaseHas('calculator_sessions', [
            'id' => $sessionId,
            'calculator_id' => $calculator->id,
            'tenant_id' => $this->tenant->id,
        ]);

        $session = CalculatorSession::find($sessionId);
        $this->assertSame(249500000, $session->input_data['price']);
        $this->assertIsNumeric($session->output_data['monthly_installment']);
        $this->assertGreaterThan(0, $session->output_data['monthly_installment']);
    }

    public function test_calculate_is_deterministic(): void
    {
        $calculator = $this->makeCreditCalculator();

        $first = $this->postWithTenant('/api/v1/calculators/'.$calculator->id.'/calculate', [
            'inputs' => ['price' => 249500000, 'dp' => 50000000, 'tenor' => 60, 'interest' => 6.5],
        ])->assertOk()->json('data.outputs');

        $second = $this->postWithTenant('/api/v1/calculators/'.$calculator->id.'/calculate', [
            'inputs' => ['price' => 249500000, 'dp' => 50000000, 'tenor' => 60, 'interest' => 6.5],
        ])->assertOk()->json('data.outputs');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('calculator_sessions', 2);
    }

    public function test_calculate_rejects_missing_required_input(): void
    {
        $calculator = $this->makeCreditCalculator();

        $this->postWithTenant('/api/v1/calculators/'.$calculator->id.'/calculate', [
            'inputs' => ['price' => 249500000, 'tenor' => 60, 'interest' => 6.5],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_calculate_rejects_out_of_range_value(): void
    {
        $calculator = $this->makeCreditCalculator();

        $this->postWithTenant('/api/v1/calculators/'.$calculator->id.'/calculate', [
            'inputs' => ['price' => 249500000, 'dp' => 50000000, 'tenor' => 999, 'interest' => 6.5],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_calculate_rejects_select_value_outside_options(): void
    {
        $calculator = Calculator::factory()->for($this->tenant)->create();
        $calculator->inputs()->create([
            'tenant_id' => $this->tenant->id,
            'key' => 'tenor',
            'label' => 'Tenor',
            'data_type' => 'select',
            'options' => [['value' => 12, 'label' => '12']],
            'sort_order' => 0,
        ]);
        $calculator->rules()->create(['tenant_id' => $this->tenant->id, 'formula' => 'tenor * 2', 'sort_order' => 0]);
        $calculator->outputs()->create(['tenant_id' => $this->tenant->id, 'key' => 'result', 'label' => 'Hasil', 'sort_order' => 0]);

        $this->postWithTenant('/api/v1/calculators/'.$calculator->id.'/calculate', [
            'inputs' => ['tenor' => 24],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_calculate_rejects_unknown_formula_function(): void
    {
        $calculator = Calculator::factory()->for($this->tenant)->create();
        $calculator->inputs()->create(['tenant_id' => $this->tenant->id, 'key' => 'a', 'label' => 'A', 'data_type' => 'number', 'sort_order' => 0]);
        $calculator->rules()->create(['tenant_id' => $this->tenant->id, 'formula' => 'magic(a)', 'sort_order' => 0]);
        $calculator->outputs()->create(['tenant_id' => $this->tenant->id, 'key' => 'result', 'label' => 'Hasil', 'sort_order' => 0]);

        $this->postWithTenant('/api/v1/calculators/'.$calculator->id.'/calculate', [
            'inputs' => ['a' => 10],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_calculate_requires_active_calculator(): void
    {
        $calculator = Calculator::factory()->for($this->tenant)->create(['status' => 'inactive']);

        $this->postWithTenant('/api/v1/calculators/'.$calculator->id.'/calculate', [
            'inputs' => ['x' => 1],
        ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_calculate_isolated_per_tenant(): void
    {
        $calculator = $this->makeCreditCalculator();
        $tenantB = Tenant::factory()->create();

        $this->withHeader('X-Tenant-ID', $tenantB->id)
            ->postJson('/api/v1/calculators/'.$calculator->id.'/calculate', [
                'inputs' => ['price' => 100000000, 'dp' => 0, 'tenor' => 12, 'interest' => 0],
            ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
