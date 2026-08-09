<?php

namespace Tests\Feature;

use App\Models\ProductCategory;
use App\Models\SalesTeam;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesTeamAdminTest extends TestCase
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

    public function test_owner_can_create_team_with_members(): void
    {
        $this->actingAsRole('owner');
        $category = ProductCategory::factory()->for($this->tenant)->create();
        $salesA = User::factory()->for($this->tenant)->role('sales')->create();
        $salesB = User::factory()->for($this->tenant)->role('sales')->create();

        $this->postJson('/api/v1/admin/sales/teams', [
            'name' => 'Team Jakarta',
            'region' => 'Jakarta',
            'product_category_id' => $category->id,
            'member_ids' => [$salesA->id, $salesB->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Team Jakarta')
            ->assertJsonCount(2, 'data.member_ids');

        $this->assertDatabaseHas('sales_team_members', [
            'sales_team_id' => SalesTeam::firstOrFail()->id,
            'user_id' => $salesA->id,
        ]);
    }

    public function test_manager_can_update_team_members(): void
    {
        $this->actingAsRole('manager');

        $team = SalesTeam::create(['tenant_id' => $this->tenant->id, 'name' => 'Old']);
        $sales = User::factory()->for($this->tenant)->role('sales')->create();
        $team->members()->attach($sales->id, ['tenant_id' => $this->tenant->id]);

        $this->putJson('/api/v1/admin/sales/teams/'.$team->id, [
            'name' => 'Team Baru',
            'member_ids' => [],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Team Baru')
            ->assertJsonCount(0, 'data.member_ids');

        $this->assertSame(0, $team->members()->count());
    }

    public function test_teams_are_isolated_per_tenant(): void
    {
        $foreignTenant = Tenant::factory()->create();
        SalesTeam::create(['tenant_id' => $foreignTenant->id, 'name' => 'Rahasia']);

        $this->actingAsRole('owner');

        $this->getJson('/api/v1/admin/sales/teams')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_team_can_be_deleted_with_members(): void
    {
        $this->actingAsRole('owner');
        $team = SalesTeam::create(['tenant_id' => $this->tenant->id, 'name' => 'Bubar']);
        $sales = User::factory()->for($this->tenant)->role('sales')->create();
        $team->members()->attach($sales->id, ['tenant_id' => $this->tenant->id]);

        $this->deleteJson('/api/v1/admin/sales/teams/'.$team->id)->assertNoContent();

        $this->assertDatabaseMissing('sales_teams', ['id' => $team->id]);
        $this->assertDatabaseMissing('sales_team_members', ['sales_team_id' => $team->id]);
    }

    public function test_sales_cannot_manage_teams(): void
    {
        $this->actingAsRole('sales');

        $this->postJson('/api/v1/admin/sales/teams', ['name' => 'x'])
            ->assertStatus(403);
    }
}
