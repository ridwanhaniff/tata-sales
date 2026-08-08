<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Lead\AssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
        $this->service = new AssignmentService;
    }

    private function makeLead(): Lead
    {
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => fake()->unique()->numerify('62813########')]);

        return Lead::factory()->for($customer)->create(['source' => 'form']);
    }

    private function makeSales(): User
    {
        return User::factory()->for($this->tenant)->role('sales')->create();
    }

    private function assignDirectly(Lead $lead, User $sales, ?\DateTimeInterface $at = null): void
    {
        LeadAssignment::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'assigned_to' => $sales->id,
            'method' => 'manual',
            'assigned_at' => $at ?? now(),
        ]);
        $lead->forceFill(['assigned_to' => $sales->id])->save();
    }

    public function test_round_robin_picks_sales_with_fewest_active_leads(): void
    {
        $salesA = $this->makeSales();
        $salesB = $this->makeSales();
        $salesC = $this->makeSales();

        $this->assignDirectly($this->makeLead(), $salesA);
        $this->assignDirectly($this->makeLead(), $salesA);
        $this->assignDirectly($this->makeLead(), $salesB);

        $lead = $this->makeLead();

        $selected = $this->service->assignRoundRobin($lead);

        $this->assertNotNull($selected);
        $this->assertSame($salesC->id, $selected->id);
        $this->assertSame($salesC->id, $lead->fresh()->assigned_to);
    }

    public function test_round_robin_tie_goes_to_longest_waiting(): void
    {
        $salesB = $this->makeSales();
        $salesC = $this->makeSales();

        $this->assignDirectly($this->makeLead(), $salesB, now()->subHours(2));
        $this->assignDirectly($this->makeLead(), $salesC, now()->subHour());

        $lead = $this->makeLead();

        $selected = $this->service->assignRoundRobin($lead);

        $this->assertSame($salesB->id, $selected->id);
    }

    public function test_round_robin_returns_null_without_sales_users(): void
    {
        $lead = $this->makeLead();

        $this->assertNull($this->service->assignRoundRobin($lead));
        $this->assertNull($lead->fresh()->assigned_to);
    }

    public function test_round_robin_ignores_won_or_lost_leads_in_workload(): void
    {
        $salesA = $this->makeSales();
        $salesB = $this->makeSales();

        $this->assignDirectly($this->makeLead(), $salesA);
        $won = $this->makeLead();
        $this->assignDirectly($won, $salesA);
        $won->forceFill(['status' => 'WON'])->save();

        $lead = $this->makeLead();

        $selected = $this->service->assignRoundRobin($lead);

        $this->assertSame($salesB->id, $selected->id);
    }

    public function test_manual_assign_records_assignment_history(): void
    {
        $salesA = $this->makeSales();
        $salesB = $this->makeSales();
        $lead = $this->makeLead();

        $this->service->assignManual($lead, $salesA, null);

        $this->service->assignManual($lead, $salesB, null);

        $this->assertSame($salesB->id, $lead->fresh()->assigned_to);
        $this->assertDatabaseCount('lead_assignments', 2);
        $this->assertSame(1, $lead->assignments()->whereNull('unassigned_at')->count());
        $this->assertSame($salesB->id, $lead->assignments()->whereNull('unassigned_at')->first()->assigned_to);
        $this->assertSame(1, $lead->assignments()->whereNotNull('unassigned_at')->count());
    }

    public function test_manual_assign_rejects_sales_from_other_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreignSales = User::factory()->for($otherTenant)->role('sales')->create();
        $lead = $this->makeLead();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->assignManual($lead, $foreignSales, null);
    }
}
