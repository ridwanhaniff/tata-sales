<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->for($this->tenant)->create(['role' => $role]);

        $this->actingAs($user);
        $this->withHeader('X-Tenant-ID', $this->tenant->id);
        app()->instance('currentTenant', $this->tenant);

        return $user;
    }

    private function makeLead(array $attributes = []): Lead
    {
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => fake()->unique()->numerify('62812########')]);

        return Lead::factory()->for($customer)->create($attributes);
    }

    public function test_sales_dashboard_is_scoped_to_own_leads(): void
    {
        $salesA = $this->actingAsRole('sales');

        $this->makeLead(['assigned_to' => $salesA->id, 'status' => 'NEW', 'temperature' => 'HOT', 'score' => 80]);
        $this->makeLead(['assigned_to' => $salesA->id, 'status' => 'WON', 'temperature' => 'HOT', 'score' => 75]);

        $salesB = User::factory()->for($this->tenant)->role('sales')->create();
        $this->makeLead(['assigned_to' => $salesB->id, 'status' => 'NEW', 'temperature' => 'HOT', 'score' => 90]);

        $this->getJson('/api/v1/admin/sales/dashboard')
            ->assertOk()
            ->assertJsonPath('data.scope', 'mine')
            ->assertJsonPath('data.my_leads_total', 2)
            ->assertJsonPath('data.new_leads', 1)
            ->assertJsonPath('data.hot_leads', 2)
            ->assertJsonPath('data.won', 1)
            ->assertJsonPath('data.lost', 0)
            ->assertJsonCount(2, 'data.recent_leads');
    }

    public function test_manager_dashboard_covers_whole_tenant(): void
    {
        $this->actingAsRole('manager');

        $salesA = User::factory()->for($this->tenant)->role('sales')->create();
        $salesB = User::factory()->for($this->tenant)->role('sales')->create();

        $this->makeLead(['assigned_to' => $salesA->id, 'status' => 'NEW', 'temperature' => 'HOT']);
        $this->makeLead(['assigned_to' => $salesB->id, 'status' => 'CONTACTED', 'temperature' => 'COLD']);
        $this->makeLead(['status' => 'NEW', 'temperature' => 'WARM']);

        $this->getJson('/api/v1/admin/sales/dashboard')
            ->assertOk()
            ->assertJsonPath('data.scope', 'all')
            ->assertJsonPath('data.my_leads_total', 3)
            ->assertJsonPath('data.hot_leads', 1)
            ->assertJsonCount(3, 'data.recent_leads');
    }

    public function test_content_manager_cannot_access_dashboard(): void
    {
        $this->actingAsRole('content_manager');

        $this->getJson('/api/v1/admin/sales/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }
}
