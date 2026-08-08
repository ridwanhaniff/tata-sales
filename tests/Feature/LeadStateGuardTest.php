<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadStateGuardTest extends TestCase
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

    private function makeLead(array $attributes = []): Lead
    {
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => fake()->unique()->numerify('62812########')]);

        return Lead::factory()->for($customer)->create($attributes);
    }

    public function test_new_to_contacted_requires_assignment(): void
    {
        $lead = $this->makeLead(['status' => 'NEW']);
        $this->actingAsRole('manager');

        $this->putJson('/api/v1/admin/leads/'.$lead->id, ['status' => 'CONTACTED'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame('NEW', $lead->fresh()->status);
    }

    public function test_sales_cannot_mark_new_lead_as_lost(): void
    {
        $sales = $this->actingAsRole('sales');
        $lead = $this->makeLead(['status' => 'NEW', 'assigned_to' => $sales->id]);

        $this->putJson('/api/v1/admin/leads/'.$lead->id, ['status' => 'LOST'])
            ->assertStatus(422);
    }

    public function test_manager_can_mark_new_lead_as_lost(): void
    {
        $this->actingAsRole('manager');
        $lead = $this->makeLead(['status' => 'NEW']);

        $this->putJson('/api/v1/admin/leads/'.$lead->id, ['status' => 'LOST'])
            ->assertOk()
            ->assertJsonPath('data.status', 'LOST');
    }

    public function test_target_status_must_exist_in_tenant_pipeline(): void
    {
        $this->actingAsRole('manager');
        $lead = $this->makeLead(['status' => 'NEW']);

        $this->putJson('/api/v1/admin/leads/'.$lead->id, ['status' => 'BOGUS'])
            ->assertStatus(422);
    }

    public function test_tenant_can_override_transitions_via_settings(): void
    {
        $this->tenant->forceFill([
            'settings' => [
                'pipeline' => [
                    'transitions' => [
                        'NEW' => ['QUALIFIED'],
                    ],
                ],
            ],
        ])->save();

        $sales = $this->actingAsRole('manager');
        $lead = $this->makeLead(['status' => 'NEW', 'assigned_to' => $sales->id]);

        $this->putJson('/api/v1/admin/leads/'.$lead->id, ['status' => 'CONTACTED'])
            ->assertStatus(422);

        $this->putJson('/api/v1/admin/leads/'.$lead->id, ['status' => 'QUALIFIED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'QUALIFIED');
    }

    public function test_default_transitions_still_applied_without_override(): void
    {
        $sales = $this->actingAsRole('manager');
        $lead = $this->makeLead(['status' => 'NEW', 'assigned_to' => $sales->id]);

        $this->putJson('/api/v1/admin/leads/'.$lead->id, ['status' => 'CONTACTED'])
            ->assertOk();

        $this->putJson('/api/v1/admin/leads/'.$lead->id, ['status' => 'QUALIFIED'])
            ->assertOk();
    }

    public function test_terminal_stages_cannot_transition_out_even_with_override(): void
    {
        $this->tenant->forceFill([
            'settings' => [
                'pipeline' => [
                    'transitions' => [
                        'WON' => ['NEGOTIATION'],
                    ],
                ],
            ],
        ])->save();

        $this->actingAsRole('manager');
        $lead = $this->makeLead(['status' => 'WON']);

        $this->putJson('/api/v1/admin/leads/'.$lead->id, ['status' => 'NEGOTIATION'])
            ->assertStatus(422);
    }

    public function test_contacted_records_response_time_event(): void
    {
        $sales = $this->actingAsRole('manager');
        $lead = $this->makeLead(['status' => 'NEW', 'assigned_to' => $sales->id]);

        $this->putJson('/api/v1/admin/leads/'.$lead->id, ['status' => 'CONTACTED'])->assertOk();

        $this->assertDatabaseHas('lead_events', [
            'lead_id' => $lead->id,
            'event_type' => 'contacted',
        ]);
    }
}
