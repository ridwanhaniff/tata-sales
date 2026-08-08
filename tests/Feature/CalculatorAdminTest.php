<?php

namespace Tests\Feature;

use App\Models\Calculator;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculatorAdminTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->for($this->tenant)->create(['role' => $role]);

        $this->actingAs($user);
        $this->withHeader('X-Tenant-ID', $this->tenant->id);
        app()->instance('currentTenant', $this->tenant);

        return $user;
    }

    private function payload(): array
    {
        return [
            'name' => 'Simulasi Kredit',
            'type' => 'credit',
            'status' => 'active',
            'inputs' => [
                ['key' => 'price', 'label' => 'Harga Kendaraan', 'data_type' => 'number', 'min_value' => 0, 'sort_order' => 0],
                ['key' => 'dp', 'label' => 'Uang Muka', 'data_type' => 'number', 'min_value' => 0, 'sort_order' => 1],
                ['key' => 'tenor', 'label' => 'Tenor (bulan)', 'data_type' => 'number', 'min_value' => 1, 'max_value' => 120, 'sort_order' => 2],
                ['key' => 'interest', 'label' => 'Bunga (%/tahun)', 'data_type' => 'number', 'min_value' => 0, 'max_value' => 30, 'sort_order' => 3],
            ],
            'rules' => [
                ['formula' => 'annuity(price - dp, interest, tenor)', 'rounding_policy' => 'round', 'sort_order' => 0],
                ['formula' => 'R1 * tenor', 'rounding_policy' => 'round', 'sort_order' => 1],
            ],
            'outputs' => [
                ['key' => 'monthly_installment', 'label' => 'Cicilan per Bulan', 'format' => 'currency', 'sort_order' => 0],
                ['key' => 'total_payment', 'label' => 'Total Pembayaran', 'format' => 'currency', 'sort_order' => 1],
            ],
        ];
    }

    public function test_manager_can_create_calculator_with_full_definition(): void
    {
        $this->actingAsRole('manager');

        $this->postJson('/api/v1/admin/calculators', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Simulasi Kredit')
            ->assertJsonPath('data.type', 'credit')
            ->assertJsonCount(4, 'data.inputs')
            ->assertJsonCount(2, 'data.rules')
            ->assertJsonCount(2, 'data.outputs');

        $this->assertDatabaseCount('calculators', 1);
        $this->assertDatabaseCount('calculator_inputs', 4);
        $this->assertDatabaseCount('calculator_rules', 2);
        $this->assertDatabaseCount('calculator_outputs', 2);
    }

    public function test_create_requires_unique_input_keys(): void
    {
        $this->actingAsRole('owner');

        $payload = $this->payload();
        $payload['inputs'][1]['key'] = 'price';

        $this->postJson('/api/v1/admin/calculators', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_manager_can_update_calculator_replacing_definition(): void
    {
        $calculator = Calculator::factory()->for($this->tenant)->create();
        $calculator->inputs()->create(['tenant_id' => $this->tenant->id, 'key' => 'lama', 'label' => 'Lama', 'data_type' => 'number']);
        $this->actingAsRole('manager');

        $payload = $this->payload();
        $payload['name'] = 'Simulasi Diperbarui';

        $this->putJson('/api/v1/admin/calculators/'.$calculator->id, $payload)
            ->assertOk()
            ->assertJsonPath('data.name', 'Simulasi Diperbarui')
            ->assertJsonCount(4, 'data.inputs');

        $this->assertDatabaseMissing('calculator_inputs', ['key' => 'lama']);
        $this->assertDatabaseCount('calculator_inputs', 4);
    }

    public function test_manager_can_show_and_delete_calculator(): void
    {
        $calculator = Calculator::factory()->for($this->tenant)->credit()->create();
        $this->actingAsRole('manager');

        $this->getJson('/api/v1/admin/calculators/'.$calculator->id)
            ->assertOk()
            ->assertJsonPath('data.name', $calculator->name)
            ->assertJsonStructure(['data' => ['id', 'name', 'type', 'status', 'inputs', 'rules', 'outputs']]);

        $this->deleteJson('/api/v1/admin/calculators/'.$calculator->id)->assertNoContent();

        $this->assertDatabaseCount('calculators', 0);
        $this->assertDatabaseCount('calculator_inputs', 0);
        $this->assertDatabaseCount('calculator_rules', 0);
        $this->assertDatabaseCount('calculator_outputs', 0);
    }

    public function test_sales_role_cannot_manage_calculators(): void
    {
        $this->actingAsRole('sales');

        $this->getJson('/api/v1/admin/calculators')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }
}
