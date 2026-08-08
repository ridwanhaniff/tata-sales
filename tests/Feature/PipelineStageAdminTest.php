<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineStageAdminTest extends TestCase
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

    public function test_owner_can_list_pipeline_stages_ordered(): void
    {
        $this->actingAsRole('owner');

        $this->getJson('/api/v1/admin/pipeline-stages')
            ->assertOk()
            ->assertJsonCount(8, 'data')
            ->assertJsonPath('data.0.key', 'NEW')
            ->assertJsonPath('data.6.key', 'LOST')
            ->assertJsonPath('data.0.is_won', false);
    }

    public function test_manager_can_create_custom_stage(): void
    {
        $this->actingAsRole('manager');

        $this->postJson('/api/v1/admin/pipeline-stages', [
            'key' => 'NEGOTIATED',
            'label' => 'Negotiated',
            'sort_order' => 9,
        ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'NEGOTIATED')
            ->assertJsonPath('data.sort_order', 9);

        $this->assertDatabaseHas('pipeline_stages', [
            'tenant_id' => $this->tenant->id,
            'key' => 'NEGOTIATED',
        ]);
    }

    public function test_duplicate_key_per_tenant_is_rejected(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/pipeline-stages', ['key' => 'NEW', 'label' => 'New Lagi'])
            ->assertStatus(422);
    }

    public function test_same_key_is_allowed_in_other_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/pipeline-stages', ['key' => 'NEW', 'label' => 'New'])
            ->assertStatus(422);

        app()->instance('currentTenant', $otherTenant);
        $this->withHeader('X-Tenant-ID', $otherTenant->id);

        $this->postJson('/api/v1/admin/pipeline-stages', ['key' => 'NEW', 'label' => 'New'])
            ->assertCreated();
    }

    public function test_only_one_is_won_and_one_is_lost_stage(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/pipeline-stages', [
            'key' => 'SIGNED',
            'label' => 'Signed',
            'is_won' => true,
        ])->assertStatus(422);

        $this->postJson('/api/v1/admin/pipeline-stages', [
            'key' => 'REJECTED',
            'label' => 'Rejected',
            'is_lost' => true,
        ])->assertStatus(422);

        $this->postJson('/api/v1/admin/pipeline-stages', [
            'key' => 'FOLLOW_UP_1',
            'label' => 'Follow-up 1',
            'is_won' => true,
            'is_lost' => true,
        ])->assertStatus(422);
    }

    public function test_custom_won_stage_can_replace_won(): void
    {
        $this->actingAsRole('owner');

        $won = PipelineStage::query()->where('key', 'WON')->firstOrFail();

        $this->putJson('/api/v1/admin/pipeline-stages/'.$won->id, [
            'key' => 'WON',
            'label' => 'Signed & Closed',
            'sort_order' => 6,
            'is_won' => true,
            'is_lost' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.label', 'Signed & Closed');
    }

    public function test_invalid_key_format_is_rejected(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/pipeline-stages', ['key' => 'negotiated', 'label' => 'x'])
            ->assertStatus(422);

        $this->postJson('/api/v1/admin/pipeline-stages', ['key' => 'NO SPACE', 'label' => 'x'])
            ->assertStatus(422);
    }

    public function test_stage_used_by_leads_cannot_be_deleted(): void
    {
        $sales = $this->actingAsRole('owner');
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '62812999'.fake()->unique()->numerify('####')]);
        Lead::factory()->for($customer)->create(['status' => 'CONTACTED', 'assigned_to' => $sales->id]);

        $stage = PipelineStage::query()->where('key', 'CONTACTED')->firstOrFail();

        $this->deleteJson('/api/v1/admin/pipeline-stages/'.$stage->id)
            ->assertStatus(422);
    }

    public function test_unused_stage_can_be_deleted(): void
    {
        $this->actingAsRole('owner');

        $stage = PipelineStage::query()->where('key', 'NURTURE')->firstOrFail();

        $this->deleteJson('/api/v1/admin/pipeline-stages/'.$stage->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('pipeline_stages', ['id' => $stage->id]);
    }

    public function test_sales_cannot_manage_pipeline_stages(): void
    {
        $this->actingAsRole('sales');

        $this->getJson('/api/v1/admin/pipeline-stages')->assertStatus(403);
        $this->postJson('/api/v1/admin/pipeline-stages', ['key' => 'X', 'label' => 'x'])->assertStatus(403);
    }
}
