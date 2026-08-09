<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Followup;
use App\Models\FollowupStep;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowUpAdminTest extends TestCase
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

    public function test_owner_can_create_followup_step(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/followup-steps', [
            'name' => 'Follow up 1',
            'trigger_event' => 'lead_created',
            'delay_minutes' => 15,
            'message' => 'Halo {customer_name}, terima kasih sudah menghubungi kami.',
            'condition' => ['field' => 'score', 'operator' => '>', 'value' => 40],
            'sort_order' => 1,
            'status' => 'active',
        ])
            ->assertCreated()
            ->assertJsonPath('data.trigger_event', 'lead_created')
            ->assertJsonPath('data.delay_minutes', 15);

        $this->assertDatabaseHas('followup_steps', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Follow up 1',
        ]);
    }

    public function test_admin_can_list_filter_and_delete_steps(): void
    {
        $this->actingAsRole('manager');
        FollowupStep::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Step A',
            'trigger_event' => 'lead_created',
            'delay_minutes' => 30,
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/admin/followup-steps?trigger_event=lead_created')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Step A');

        $step = FollowupStep::query()->firstOrFail();

        $this->deleteJson('/api/v1/admin/followup-steps/'.$step->id)->assertNoContent();
        $this->assertDatabaseMissing('followup_steps', ['id' => $step->id]);
    }

    public function test_invalid_step_payload_is_rejected(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/followup-steps', [
            'name' => 'x',
            'delay_minutes' => -5,
        ])->assertStatus(422);
    }

    public function test_sales_cannot_manage_steps(): void
    {
        $this->actingAsRole('sales');

        $this->postJson('/api/v1/admin/followup-steps', [
            'name' => 'Step',
            'trigger_event' => 'lead_created',
            'delay_minutes' => 5,
        ])->assertStatus(403);
    }

    public function test_lead_transition_schedules_matching_followup_step(): void
    {
        $this->actingAsRole('manager');

        FollowupStep::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Nurture follow-up',
            'trigger_event' => 'lead_nurture',
            'delay_minutes' => 45,
            'message' => 'Halo {customer_name}, masih tertarik?',
            'condition' => null,
            'status' => 'active',
        ]);

        $sales = User::factory()->for($this->tenant)->role('sales')->create();
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '6281299887766']);
        $lead = Lead::factory()->for($customer)->create(['status' => 'NEW', 'assigned_to' => $sales->id]);

        $this->putJson('/api/v1/admin/leads/'.$lead->id, ['status' => 'NURTURE'])
            ->assertOk();

        $this->assertDatabaseHas('followups', [
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'status' => 'pending',
        ]);

        $followup = Followup::query()->firstOrFail();
        $this->assertEqualsWithDelta(now()->addMinutes(45)->timestamp, $followup->scheduled_at->timestamp, 5);
        $this->assertSame('Halo '.$customer->name.', masih tertarik?', $followup->message);
    }

    public function test_condition_controls_schedule(): void
    {
        $this->actingAsRole('manager');

        FollowupStep::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hanya hot lead',
            'trigger_event' => 'lead_contacted',
            'delay_minutes' => 10,
            'message' => 'Spesial untuk Anda',
            'condition' => ['field' => 'temperature', 'operator' => '==', 'value' => 'HOT'],
            'status' => 'active',
        ]);

        $sales = User::factory()->for($this->tenant)->role('sales')->create();
        $customerA = Customer::factory()->for($this->tenant)->create(['phone' => '6281299807767']);
        $cold = Lead::factory()->for($customerA)->create(['status' => 'NEW', 'assigned_to' => $sales->id, 'temperature' => 'COLD']);

        $this->putJson('/api/v1/admin/leads/'.$cold->id, ['status' => 'CONTACTED'])->assertOk();
        $this->assertSame(0, Followup::count());

        $customerB = Customer::factory()->for($this->tenant)->create(['phone' => '6281299807768']);
        $hot = Lead::factory()->for($customerB)->create(['status' => 'NEW', 'assigned_to' => $sales->id, 'temperature' => 'HOT']);

        $this->putJson('/api/v1/admin/leads/'.$hot->id, ['status' => 'CONTACTED'])->assertOk();
        $this->assertSame(1, Followup::count());
    }
}
