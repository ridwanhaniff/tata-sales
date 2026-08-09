<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentContext;
use App\Agents\HandoffAgent;
use App\Agents\Support\ToolExecutor;
use App\Agents\Tools\RequestHumanTool;
use App\Models\AiAgentLog;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLLMProvider;
use Tests\TestCase;

class HandoffAgentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
    }

    private function agent(FakeLLMProvider $fake): HandoffAgent
    {
        return new HandoffAgent($fake, new ToolExecutor);
    }

    private function makeConversation(): Conversation
    {
        return Conversation::create([
            'tenant_id' => $this->tenant->id,
            'status' => Conversation::STATUS_AI_ACTIVE,
            'channel' => 'webchat',
            'context' => [],
        ]);
    }

    private function context(Conversation $conversation): AgentContext
    {
        return new AgentContext(
            message: 'Saya minta bicara dengan manusia.',
            tenant: $this->tenant,
            conversationId: $conversation->id,
        );
    }

    public function test_request_human_is_the_only_tool(): void
    {
        $agent = $this->agent(new FakeLLMProvider);

        $this->assertSame(['request_human'], array_map(fn ($t) => $t->name(), $agent->tools()));
    }

    public function test_agent_handoff_sets_waiting_human_and_notifies_sales_and_managers(): void
    {
        User::factory()->for($this->tenant)->create(['role' => 'owner']);
        $sales = User::factory()->for($this->tenant)->create(['role' => 'sales']);

        $conversation = $this->makeConversation();

        $fake = new FakeLLMProvider([
            FakeLLMProvider::toolCall('request_human', ['conversation_id' => $conversation->id, 'reason' => 'customer minta manusia']),
            FakeLLMProvider::text('Baik, saya hubungkan Anda dengan tim kami.'),
        ]);

        $result = $this->agent($fake)->handle($this->context($conversation));

        $this->assertSame($conversation->id, $result['handoff']['conversation_id']);
        $this->assertSame(Conversation::STATUS_WAITING_HUMAN, $result['handoff']['status']);

        $conversation->refresh();
        $this->assertSame(Conversation::STATUS_WAITING_HUMAN, $conversation->status);

        // Pesan sistem tercatat
        $system = ConversationMessage::where('conversation_id', $conversation->id)
            ->where('sender_type', ConversationMessage::SENDER_SYSTEM)
            ->first();
        $this->assertNotNull($system);
        $this->assertSame('ai', $system->metadata['source']);

        // Notifikasi ke owner/sales
        $notifications = Notification::where('type', 'chat_handoff')->get();
        $this->assertCount(2, $notifications);
        $this->assertTrue($notifications->pluck('user_id')->contains($sales->id));

        // Tool call ter-log
        $log = AiAgentLog::where('agent', 'handoff')->where('tool_called', 'request_human')->first();
        $this->assertNotNull($log);
        $this->assertSame(AiAgentLog::STATUS_SUCCESS, $log->status);

        $this->assertSame(2, $fake->generateCalls);
    }

    public function test_tool_denies_foreign_tenant_conversation(): void
    {
        $foreign = Conversation::create([
            'tenant_id' => Tenant::factory()->create()->id,
            'status' => Conversation::STATUS_AI_ACTIVE,
            'channel' => 'webchat',
            'context' => [],
        ]);

        $tool = new RequestHumanTool;
        $output = $tool->execute(['conversation_id' => $foreign->id, 'reason' => 'tes']);

        $this->assertFalse($output['done']);
        $this->assertSame('Percakapan tidak ditemukan.', $output['reason']);
    }

    public function test_agent_without_handoff_returns_null(): void
    {
        $conversation = $this->makeConversation();

        $fake = new FakeLLMProvider([
            FakeLLMProvider::text('Silakan lanjutkan percakapan ini.'),
        ]);

        $result = $this->agent($fake)->handle($this->context($conversation));

        $this->assertNull($result['handoff']);
        $this->assertSame(1, $fake->generateCalls);
    }

    public function test_handoff_marks_high_value_lead_flag(): void
    {
        // Trigger tabel §6: lead bernilai tinggi di-flag saat handoff
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'phone' => '6281234567890']);
        $lead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'estimated_value' => 900_000_000,
            'status' => 'QUALIFIED',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'lead_id' => $lead->id,
            'status' => Conversation::STATUS_AI_ACTIVE,
            'channel' => 'webchat',
            'context' => [],
        ]);
        User::factory()->for($this->tenant)->create(['role' => 'manager']);

        $tool = new RequestHumanTool;
        $tool->execute(['conversation_id' => $conversation->id, 'reason' => 'customer minta manusia']);

        $notification = Notification::where('type', 'chat_handoff')->first();
        $this->assertNotNull($notification);
        $this->assertSame($lead->id, $notification->data['lead_id'] ?? null);
        $this->assertSame($conversation->id, $notification->data['conversation_id'] ?? null);
    }
}
