<?php

namespace Tests\Feature;

use App\Models\SalesTarget;
use App\Models\SalesTeam;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesTargetAdminTest extends TestCase
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

    public function test_owner_can_set_target_for_sales(): void
    {
        $this->actingAsRole('owner');
        $sales = User::factory()->for($this->tenant)->role('sales')->create();

        $this->postJson('/api/v1/admin/sales/targets', [
            'period' => '2026-08',
            'user_id' => $sales->id,
            'target_leads' => 40,
            'target_revenue' => 1200000000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.target_leads', 40)
            ->assertJsonPath('data.period', '2026-08');
    }

    public function test_target_for_team_is_allowed(): void
    {
        $this->actingAsRole('manager');
        $team = SalesTeam::create(['tenant_id' => $this->tenant->id, 'name' => 'Team Bekasi']);

        $this->postJson('/api/v1/admin/sales/targets', [
            'period' => '2026-09',
            'sales_team_id' => $team->id,
            'target_leads' => 25,
        ])->assertCreated();
    }

    public function test_target_with_user_and_team_both_is_rejected(): void
    {
        $this->actingAsRole('owner');
        $team = SalesTeam::create(['tenant_id' => $this->tenant->id, 'name' => 'Team X']);
        $sales = User::factory()->for($this->tenant)->role('sales')->create();

        $this->postJson('/api/v1/admin/sales/targets', [
            'period' => '2026-08',
            'user_id' => $sales->id,
            'sales_team_id' => $team->id,
        ])->assertStatus(422);
    }

    public function test_duplicate_target_same_period_is_rejected(): void
    {
        $this->actingAsRole('owner');
        $sales = User::factory()->for($this->tenant)->role('sales')->create();

        SalesTarget::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $sales->id,
            'period' => '2026-08',
            'target_leads' => 10,
        ]);

        $this->postJson('/api/v1/admin/sales/targets', [
            'period' => '2026-08',
            'user_id' => $sales->id,
            'target_leads' => 50,
        ])->assertStatus(422);
    }

    public function test_invalid_period_format_is_rejected(): void
    {
        $this->actingAsRole('owner');
        $sales = User::factory()->for($this->tenant)->role('sales')->create();

        $this->postJson('/api/v1/admin/sales/targets', [
            'period' => 'agustus',
            'user_id' => $sales->id,
        ])->assertStatus(422);
    }

    public function test_targets_are_isolated_per_tenant(): void
    {
        $foreignTenant = Tenant::factory()->create();
        $foreignSales = User::factory()->for($foreignTenant)->role('sales')->create();
        SalesTarget::create([
            'tenant_id' => $foreignTenant->id,
            'user_id' => $foreignSales->id,
            'period' => '2026-08',
        ]);

        $this->actingAsRole('owner');

        $this->getJson('/api/v1/admin/sales/targets')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_sales_cannot_manage_targets(): void
    {
        $this->actingAsRole('sales');
        $manager = User::factory()->for($this->tenant)->role('manager')->create();

        $this->postJson('/api/v1/admin/sales/targets', [
            'period' => '2026-08',
            'user_id' => $manager->id,
        ])->assertStatus(403);
    }
}
