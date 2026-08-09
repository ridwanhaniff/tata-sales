<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentContext;
use App\Agents\FollowupAgent;
use App\Agents\Support\ToolExecutor;
use App\Agents\Tools\CreateFollowUpTool;
use App\Models\AiAgentLog;
use App\Models\Followup;
use App\Models\FollowupStep;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\FollowUp\FollowUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLLMProvider;
use Tests\TestCase;

class FollowupAgentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
    }

    private function agent(FakeLLMProvider $fake): FollowupAgent
    {
        return new FollowupAgent($fake, new ToolExecutor);
    }

    private function makeLead(): Lead
    {
        return Lead::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'NEW']);
    }

    private function makeStep(string $trigger = 'ai.followup', int $delay = 1440): FollowupStep
    {
        return FollowupStep::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Follow-up AI',
            'trigger_event' => $trigger,
            'delay_minutes' => $delay,
            'condition' => [],
            'action' => 'create_followup',
            'sort_order' => 0,
            'status' => 'active',
            'message' => 'Halo {customer_name}, ada info terbaru untuk {product_name}.',
        ]);
    }

    private function context(Lead $lead, FollowupStep $step): AgentContext
    {
        return new AgentContext(
            message: 'Tulis follow-up untuk lead ini sesuai step yang diberi.',
            tenant: $this->tenant,
            leadId: $lead->id,
            meta: [
                'followup_step' => ['id' => $step->id],
                'lead' => ['id' => $lead->id, 'status' => $lead->status],
            ],
        );
    }

    public function test_create_followup_is_the_only_tool(): void
    {
        $agent = $this->agent(new FakeLLMProvider);

        $this->assertSame(['create_followup'], array_map(fn ($t) => $t->name(), $agent->tools()));
    }

    public function test_agent_schedules_pending_followup_within_rule_window(): void
    {
        $lead = $this->makeLead();
        $step = $this->makeStep();

        $fake = new FakeLLMProvider([
            FakeLLMProvider::toolCall('create_followup', [
                'lead_id' => $lead->id,
                'step_id' => $step->id,
                'message' => 'Halo, bagaimana kabarnya? Kami ada info terbaru untuk Anda.',
                'channel' => 'whatsapp',
            ]),
            FakeLLMProvider::text('Saya sudah jadwalkan follow-up D+1 untuk lead ini.'),
        ]);

        $result = $this->agent($fake)->handle($this->context($lead, $step));

        $followup = Followup::query()->first();
        $this->assertNotNull($followup);
        $this->assertSame($followup->id, $result['followup_id']);
        $this->assertSame('pending', $followup->status);
        $this->assertSame('whatsapp', $followup->channel);
        $this->assertSame('Halo, bagaimana kabarnya? Kami ada info terbaru untuk Anda.', $followup->message);
        $this->assertSame($lead->id, $followup->lead_id);

        // Jadwal sesuai rule step (delay_minutes), bukan waktu sebar
        $this->assertTrue($followup->scheduled_at->greaterThan(now()));
        $this->assertTrue($followup->sent_at === null);

        $log = AiAgentLog::query()->where('agent', 'followup')->where('tool_called', 'create_followup')->first();
        $this->assertNotNull($log);
        $this->assertSame(AiAgentLog::STATUS_SUCCESS, $log->status);
        $this->assertSame($lead->id, $log->lead_id);

        $this->assertSame(2, $fake->generateCalls);
    }

    public function test_copy_is_never_sent_directly(): void
    {
        $lead = $this->makeLead();
        $step = $this->makeStep();

        $tool = new CreateFollowUpTool(app(FollowUpService::class));
        $output = $tool->execute([
            'lead_id' => $lead->id,
            'step_id' => $step->id,
            'message' => 'Follow-up draft.',
        ]);

        $this->assertTrue($output['done']);
        $this->assertSame('pending', $output['status']);
        $this->assertSame(0, Followup::where('status', 'sent')->count());
    }

    public function test_tool_denies_inactive_or_foreign_step(): void
    {
        $lead = $this->makeLead();
        $foreignTenant = Tenant::factory()->create();
        $foreignStep = FollowupStep::create([
            'tenant_id' => $foreignTenant->id,
            'name' => 'Step Tenant Lain',
            'trigger_event' => 'ai.followup',
            'delay_minutes' => 30,
            'action' => 'create_followup',
            'status' => 'active',
        ]);

        $tool = new CreateFollowUpTool(app(FollowUpService::class));
        $output = $tool->execute(['lead_id' => $lead->id, 'step_id' => $foreignStep->id]);

        $this->assertFalse($output['done']);
        $this->assertSame(0, Followup::count());
    }

    public function test_guardrail_inactive_step_rejected(): void
    {
        $lead = $this->makeLead();
        $step = $this->makeStep();
        $step->forceFill(['status' => 'inactive'])->save();

        $tool = new CreateFollowUpTool(app(FollowUpService::class));
        $output = $tool->execute(['lead_id' => $lead->id, 'step_id' => $step->id]);

        $this->assertFalse($output['done']);
        $this->assertSame(0, Followup::count());
    }

    public function test_agent_without_tool_returns_null_followup(): void
    {
        $lead = $this->makeLead();
        $step = $this->makeStep();

        $fake = new FakeLLMProvider([
            FakeLLMProvider::text('Tidak ada jadwal follow-up untuk lead ini.'),
        ]);

        $result = $this->agent($fake)->handle($this->context($lead, $step));

        $this->assertNull($result['followup_id']);
        $this->assertSame(1, $fake->generateCalls);
    }
}
