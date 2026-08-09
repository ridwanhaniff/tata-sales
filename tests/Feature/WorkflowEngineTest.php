<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Followup;
use App\Models\FollowupStep;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLog;
use App\Models\WorkflowNode;
use App\Models\WorkflowRun;
use App\Services\Workflow\WorkflowEngine;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
        $this->seed([PipelineStageSeeder::class]);

        $this->engine = app(WorkflowEngine::class);
    }

    private function makeLead(array $attributes = []): Lead
    {
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => fake()->unique()->numerify('62812########')]);

        return Lead::factory()->for($customer)->create($attributes);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function makeWorkflow(string $triggerEvent, array $nodes, string $status = 'active'): Workflow
    {
        $workflow = Workflow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'WF '.$triggerEvent,
            'trigger_event' => $triggerEvent,
            'status' => $status,
            'definition' => $nodes,
        ]);

        $first = true;
        $previous = null;

        foreach ($nodes as $index => $node) {
            $created = WorkflowNode::create([
                'tenant_id' => $this->tenant->id,
                'workflow_id' => $workflow->id,
                'node_type' => $node['node_type'],
                'config' => $node['config'] ?? [],
                'sort_order' => $index,
            ]);

            if ($previous) {
                $previous->forceFill(['next_node_id' => $created->id])->save();
            }

            $previous = $created;
        }

        return $workflow;
    }

    public function test_lead_created_triggers_workflow_and_creates_followup(): void
    {
        $this->makeWorkflow('lead_created', [
            ['node_type' => 'trigger'],
            ['node_type' => 'condition', 'config' => ['field' => 'score', 'operator' => '>', 'value' => 30]],
            ['node_type' => 'action', 'config' => ['action' => 'create_followup', 'message' => 'Halo {customer_name}, bagaimana kabarnya?', 'delay_minutes' => 120]],
            ['node_type' => 'end'],
        ]);

        $sales = User::factory()->for($this->tenant)->role('sales')->create();
        $lead = $this->makeLead(['score' => 80, 'assigned_to' => $sales->id]);

        $this->engine->trigger('lead_created', $lead);

        $run = WorkflowRun::query()->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame($lead->id, $run->lead_id);

        $followup = Followup::query()->first();
        $this->assertNotNull($followup);
        $this->assertSame('pending', $followup->status);
        $this->assertStringContainsString('kabarnya', $followup->message ?? '');
        $this->assertSame('whatsapp', $followup->channel);

        $this->assertSame(4, WorkflowLog::query()->count());
    }

    public function test_condition_false_aborts_run_without_action(): void
    {
        $this->makeWorkflow('lead_created', [
            ['node_type' => 'trigger'],
            ['node_type' => 'condition', 'config' => ['field' => 'score', 'operator' => '>', 'value' => 90]],
            ['node_type' => 'action', 'config' => ['action' => 'create_followup', 'delay_minutes' => 60]],
        ]);

        $lead = $this->makeLead(['score' => 20]);

        $this->engine->trigger('lead_created', $lead);

        $run = WorkflowRun::query()->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame(0, Followup::count());
        $this->assertDatabaseHas('workflow_logs', [
            'workflow_run_id' => $run->id,
            'status' => 'skipped',
        ]);
    }

    public function test_inactive_workflow_is_not_triggered(): void
    {
        $this->makeWorkflow('lead_created', [
            ['node_type' => 'trigger'],
        ], 'paused');

        $lead = $this->makeLead(['score' => 80]);

        $this->engine->trigger('lead_created', $lead);

        $this->assertSame(0, WorkflowRun::count());
    }

    public function test_workflow_only_fires_for_matching_event(): void
    {
        $this->makeWorkflow('lead_contacted', [
            ['node_type' => 'trigger'],
        ]);

        $lead = $this->makeLead();

        $this->engine->trigger('lead_created', $lead);
        $this->assertSame(0, WorkflowRun::count());

        $this->engine->trigger('lead_contacted', $lead);
        $this->assertSame(1, WorkflowRun::count());
    }

    public function test_lead_transition_action_updates_lead_status(): void
    {
        $sales = User::factory()->for($this->tenant)->role('sales')->create();
        $this->makeWorkflow('lead_created', [
            ['node_type' => 'trigger'],
            ['node_type' => 'action', 'config' => ['action' => 'lead_transition', 'to' => 'NURTURE']],
        ]);

        $lead = $this->makeLead(['status' => 'NEW', 'score' => 10, 'assigned_to' => $sales->id]);

        $this->engine->trigger('lead_created', $lead);

        $this->assertSame('NURTURE', $lead->fresh()->status);
        $run = WorkflowRun::query()->firstOrFail();
        $this->assertSame('completed', $run->status);
    }

    public function test_illegal_transition_in_workflow_fails_run(): void
    {
        $this->makeWorkflow('lead_created', [
            ['node_type' => 'trigger'],
            ['node_type' => 'action', 'config' => ['action' => 'lead_transition', 'to' => 'WON']],
        ]);

        $lead = $this->makeLead(['status' => 'NEW']);

        $this->engine->trigger('lead_created', $lead);

        $run = WorkflowRun::query()->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame('NEW', $lead->fresh()->status);
    }

    public function test_ai_and_human_nodes_are_stubbed_as_skipped(): void
    {
        $this->makeWorkflow('lead_created', [
            ['node_type' => 'trigger'],
            ['node_type' => 'ai', 'config' => ['prompt' => '...']],
            ['node_type' => 'human'],
            ['node_type' => 'end'],
        ]);

        $lead = $this->makeLead();

        $this->engine->trigger('lead_created', $lead);

        $run = WorkflowRun::query()->firstOrFail();
        $this->assertSame('completed', $run->status);

        $skipped = WorkflowLog::query()->where('status', 'skipped')->whereIn('node_id', WorkflowNode::pluck('id'))->get();
        $this->assertCount(2, WorkflowLog::query()->where('status', 'skipped')->get());
        $this->assertDatabaseHas('workflow_logs', ['workflow_run_id' => $run->id, 'status' => 'skipped']);
    }

    public function test_delay_node_schedules_pending_followup(): void
    {
        $this->makeWorkflow('lead_created', [
            ['node_type' => 'trigger'],
            ['node_type' => 'delay', 'config' => ['minutes' => 45]],
        ]);

        $lead = $this->makeLead();

        $this->engine->trigger('lead_created', $lead);

        $followup = Followup::query()->firstOrFail();
        $this->assertSame('workflow', $followup->channel);
        $this->assertEqualsWithDelta(now()->addMinutes(45)->timestamp, $followup->scheduled_at->timestamp, 5);
    }

    public function test_each_workflow_run_is_isolated_per_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $customer = Customer::factory()->for($otherTenant)->create(['phone' => '62812'.fake()->unique()->numerify('########')]);
        $leadB = Lead::factory()->for($customer)->create(['tenant_id' => $otherTenant->id]);

        $this->makeWorkflow('lead_created', [
            ['node_type' => 'trigger'],
        ]);

        $this->engine->trigger('lead_created', $leadB->fresh());

        $this->assertSame(0, WorkflowRun::query()->count());
        $this->assertSame(0, WorkflowRun::query()->withoutGlobalScope('tenant')->count());
    }

    public function test_lead_submission_flows_into_workflow_and_followup(): void
    {
        $user = User::factory()->for($this->tenant)->role('sales')->create();

        $workflow = Workflow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Auto follow-up',
            'trigger_event' => 'lead_created',
            'status' => 'active',
            'definition' => [],
        ]);
        $workflow->nodes()->create([
            'tenant_id' => $this->tenant->id,
            'node_type' => 'trigger',
            'config' => [],
            'sort_order' => 0,
        ]);

        FollowupStep::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Follow 1',
            'trigger_event' => 'lead_created',
            'delay_minutes' => 60,
            'message' => 'Halo {customer_name}, kami dari showroom, kabar baik?',
            'action' => 'create_followup',
            'sort_order' => 0,
            'status' => 'active',
        ]);

        $this->withHeader('X-Tenant-ID', $this->tenant->id)->postJson('/api/v1/leads', [
            'customer' => ['name' => 'Sari', 'phone' => '6281299000099'],
            'product_id' => null,
            'source' => 'form',
            'consent_marketing' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('workflow_runs', [
            'tenant_id' => $this->tenant->id,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('followups', [
            'tenant_id' => $this->tenant->id,
            'status' => 'pending',
            'channel' => 'whatsapp',
        ]);

        $followup = Followup::query()->firstOrFail();
        $this->assertSame('Halo Sari, kami dari showroom, kabar baik?', $followup->message);
    }
}
