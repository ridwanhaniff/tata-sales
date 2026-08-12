<?php

namespace Tests\Feature\Agents;

use App\Agents\Tools\AssignSalesTool;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Lead\AssignmentService;
use App\Services\Lead\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssignSalesToolTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
    }

    private function makeLead(): Lead
    {
        return Lead::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'NEW']);
    }

    private function makeSales(): User
    {
        return User::factory()->for($this->tenant)->role(User::ROLE_SALES)->create(['status' => 'active']);
    }

    private function tool(): AssignSalesTool
    {
        return new AssignSalesTool(app(AssignmentService::class), app(LeadService::class));
    }

    public function test_assigns_lead_to_active_sales_and_logs_event(): void
    {
        $sales = $this->makeSales();
        $lead = $this->makeLead();

        $result = $this->tool()->execute(['lead_id' => $lead->id]);

        $this->assertTrue($result['done']);
        $this->assertSame($sales->id, $result['assigned_to']['id']);
        $this->assertSame($sales->id, $lead->fresh()->assigned_to);

        $this->assertSame(1, LeadEvent::where('lead_id', $lead->id)->where('event_type', 'sales_assigned')->count());
        $this->assertSame(1, Notification::where('user_id', $sales->id)->where('type', 'new_lead')->count());
    }

    public function test_respects_requested_method(): void
    {
        $this->makeSales();
        $lead = $this->makeLead();

        $result = $this->tool()->execute(['lead_id' => $lead->id, 'method' => 'workload']);

        $this->assertTrue($result['done']);
        $this->assertSame('workload', $result['method']);

        $event = LeadEvent::where('lead_id', $lead->id)->where('event_type', 'sales_assigned')->firstOrFail();
        $this->assertSame('workload', $event->event_data['method']);
    }

    public function test_refuses_lead_already_assigned(): void
    {
        $sales = $this->makeSales();
        $lead = $this->makeLead();
        app(AssignmentService::class)->assignRoundRobin($lead);

        $result = $this->tool()->execute(['lead_id' => $lead->id]);

        $this->assertFalse($result['done']);
        $this->assertStringContainsString('sudah di-assign', $result['reason']);
        $this->assertSame($sales->id, $result['assigned_to']['id']);
    }

    public function test_refuses_when_no_active_sales_available(): void
    {
        $lead = $this->makeLead();

        $result = $this->tool()->execute(['lead_id' => $lead->id]);

        $this->assertFalse($result['done']);
        $this->assertStringContainsString('sales aktif', $result['reason']);
        $this->assertNull($lead->fresh()->assigned_to);
    }

    public function test_refuses_unknown_lead(): void
    {
        $this->makeSales();

        $result = $this->tool()->execute(['lead_id' => (string) Str::uuid()]);

        $this->assertFalse($result['done']);
        $this->assertStringContainsString('tidak ditemukan', $result['reason']);
    }

    public function test_refuses_lead_from_other_tenant(): void
    {
        $this->makeSales();
        $otherTenant = Tenant::factory()->create();
        $foreign = Lead::factory()->create(['tenant_id' => $otherTenant->id, 'status' => 'NEW']);

        $result = $this->tool()->execute(['lead_id' => $foreign->id]);

        // tenant scope memfilter lead asing → dianggap tidak ditemukan
        $this->assertFalse($result['done']);
        $this->assertStringContainsString('tidak ditemukan', $result['reason']);
    }
}
