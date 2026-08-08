<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadAdminTest extends TestCase
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
        app()->instance('currentTenant', $this->tenant);

        return $user;
    }

    private function makeLead(array $attributes = []): Lead
    {
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => fake()->unique()->numerify('62812########')]);

        return Lead::factory()->for($customer)->create($attributes);
    }

    public function test_manager_can_list_leads_with_filters(): void
    {
        $productA = Product::factory()->for($this->tenant)->create();
        $productB = Product::factory()->for($this->tenant)->create();
        $campaign = Campaign::factory()->for($this->tenant)->create();
        $sales = User::factory()->for($this->tenant)->role('sales')->create();

        $this->makeLead(['product_id' => $productA->id, 'status' => 'NEW', 'temperature' => 'HOT', 'score' => 70]);
        $this->makeLead(['product_id' => $productB->id, 'status' => 'CONTACTED', 'temperature' => 'COLD', 'assigned_to' => $sales->id, 'source' => 'whatsapp']);
        $this->makeLead(['campaign_id' => $campaign->id, 'status' => 'WON', 'temperature' => 'HOT']);

        $this->actingAsRole('owner');

        $this->getJson('/api/v1/admin/leads?status=NEW')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product.id', $productA->id);

        $this->getJson('/api/v1/admin/leads?temperature=HOT')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/admin/leads?product='.$productB->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/admin/leads?campaign='.$campaign->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/admin/leads?sales='.$sales->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/admin/leads?source=whatsapp')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_sales_sees_only_own_leads(): void
    {
        $sales = $this->actingAsRole('sales');
        $this->makeLead(['assigned_to' => $sales->id]);
        $this->makeLead();

        $this->getJson('/api/v1/admin/leads')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.assigned_to.id', $sales->id);
    }

    public function test_sales_cannot_view_foreign_lead(): void
    {
        $this->actingAsRole('sales');
        $foreign = $this->makeLead();

        $this->getJson('/api/v1/admin/leads/'.$foreign->id)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_manager_can_update_status_via_valid_transition(): void
    {
        $sales = User::factory()->for($this->tenant)->role('sales')->create();
        $lead = $this->makeLead(['status' => 'NEW', 'assigned_to' => $sales->id]);
        $this->actingAsRole('manager');

        $this->putJson('/api/v1/admin/leads/'.$lead->id, ['status' => 'CONTACTED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CONTACTED');

        $this->assertDatabaseHas('lead_events', [
            'lead_id' => $lead->id,
            'event_type' => 'contacted',
        ]);
    }

    public function test_illegal_transition_is_rejected(): void
    {
        $lead = $this->makeLead(['status' => 'NEW']);
        $this->actingAsRole('manager');

        $this->putJson('/api/v1/admin/leads/'.$lead->id, ['status' => 'WON'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame('NEW', $lead->fresh()->status);
    }

    public function test_terminal_status_cannot_be_left(): void
    {
        $lead = $this->makeLead(['status' => 'NEGOTIATION']);
        $this->actingAsRole('manager');

        $this->putJson('/api/v1/admin/leads/'.$lead->id, ['status' => 'WON'])->assertOk();

        $this->putJson('/api/v1/admin/leads/'.$lead->id, ['status' => 'CONTACTED'])
            ->assertStatus(422);
    }

    public function test_manager_can_assign_lead_manually(): void
    {
        $lead = $this->makeLead();
        $sales = User::factory()->for($this->tenant)->role('sales')->create();
        $this->actingAsRole('manager');

        $this->postJson('/api/v1/admin/leads/'.$lead->id.'/assign', ['user_id' => $sales->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_to.id', $sales->id);

        $this->assertDatabaseHas('lead_assignments', [
            'lead_id' => $lead->id,
            'assigned_to' => $sales->id,
            'method' => 'manual',
        ]);
        $this->assertDatabaseHas('lead_events', [
            'lead_id' => $lead->id,
            'event_type' => 'sales_assigned',
        ]);
    }

    public function test_assign_rejects_sales_from_other_tenant(): void
    {
        $lead = $this->makeLead();
        $foreignTenant = Tenant::factory()->create();
        $foreignSales = User::factory()->for($foreignTenant)->role('sales')->create();
        $this->actingAsRole('manager');

        $this->postJson('/api/v1/admin/leads/'.$lead->id.'/assign', ['user_id' => $foreignSales->id])
            ->assertStatus(422);
    }

    public function test_sales_can_add_note_to_own_lead(): void
    {
        $sales = $this->actingAsRole('sales');
        $lead = $this->makeLead(['assigned_to' => $sales->id]);

        $this->postJson('/api/v1/admin/leads/'.$lead->id.'/notes', ['content' => 'Sudah dihubungi via WA.'])
            ->assertCreated()
            ->assertJsonPath('data.content', 'Sudah dihubungi via WA.');

        $this->assertDatabaseHas('notes', [
            'lead_id' => $lead->id,
            'user_id' => $sales->id,
        ]);
        $this->assertDatabaseHas('lead_events', [
            'lead_id' => $lead->id,
            'event_type' => 'note_added',
        ]);
    }

    public function test_content_manager_cannot_access_leads(): void
    {
        $this->actingAsRole('content_manager');

        $this->getJson('/api/v1/admin/leads')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }
}
