<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationAdminTest extends TestCase
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

    private function conversationFor(User $sales, ?User $assignee = null): Conversation
    {
        $customer = Customer::factory()->for($this->tenant)->create([
            'phone' => fake()->unique()->numerify('62812########'),
        ]);
        $lead = Lead::factory()->for($customer)->create([
            'status' => 'NEW',
            'assigned_to' => $assignee?->id ?? $sales->id,
        ]);

        return Conversation::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => Conversation::STATUS_AI_ACTIVE,
            'context' => [],
        ]);
    }

    public function test_sales_sees_only_own_conversations(): void
    {
        $this->actingAsRole('sales');
        $salesB = User::factory()->for($this->tenant)->role('sales')->create();

        $other = User::factory()->for($this->tenant)->role('sales')->create();

        $this->conversationFor($other);
        $this->conversationFor($salesB);

        $this->getJson('/api/v1/admin/conversations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_owner_sees_all_conversations(): void
    {
        $this->actingAsRole('owner');
        $salesA = User::factory()->for($this->tenant)->role('sales')->create();
        $salesB = User::factory()->for($this->tenant)->role('sales')->create();

        $this->conversationFor($salesA);
        $this->conversationFor($salesB);

        $this->getJson('/api/v1/admin/conversations')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_sales_can_read_messages_and_reply(): void
    {
        $sales = $this->actingAsRole('sales');
        $conversation = $this->conversationFor($sales);

        $conversation->messages()->create([
            'tenant_id' => $this->tenant->id,
            'sender_type' => ConversationMessage::SENDER_CUSTOMER,
            'content' => 'Halo, harga berapa?',
        ]);

        $this->getJson('/api/v1/admin/conversations/'.$conversation->id.'/messages')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson('/api/v1/admin/conversations/'.$conversation->id.'/reply', [
            'content' => 'Selamat siang, harga mulai 250 jutaan ya.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.sender_type', 'sales');

        $this->assertSame(Conversation::STATUS_HUMAN_ACTIVE, $conversation->fresh()->status);
        $this->assertSame($sales->id, $conversation->fresh()->assigned_to);
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_sales_cannot_reply_to_other_sales_conversation(): void
    {
        $this->actingAsRole('sales');
        $salesB = User::factory()->for($this->tenant)->role('sales')->create();

        $conversation = $this->conversationFor($salesB, $salesB);

        $this->postJson('/api/v1/admin/conversations/'.$conversation->id.'/reply', [
            'content' => 'Ngebut.',
        ])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_conversations_are_isolated_per_tenant(): void
    {
        $foreignTenant = Tenant::factory()->create();
        $foreignSales = User::factory()->for($foreignTenant)->role('sales')->create();

        $foreignCustomer = Customer::factory()->for($foreignTenant)->create(['phone' => '6281299112233']);
        $lead = Lead::factory()->for($foreignCustomer)->create(['status' => 'NEW', 'assigned_to' => $foreignSales->id]);
        Conversation::create([
            'tenant_id' => $foreignTenant->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'status' => Conversation::STATUS_AI_ACTIVE,
        ]);

        $this->actingAsRole('owner');

        $this->getJson('/api/v1/admin/conversations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_handoff_to_human_sets_waiting_human_and_notifies(): void
    {
        $this->actingAsRole('manager');
        $sales = User::factory()->for($this->tenant)->role('sales')->create();
        $conversation = $this->conversationFor($sales, $sales);

        $this->postJson('/api/v1/admin/conversations/'.$conversation->id.'/handoff', [
            'to' => 'human',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Conversation::STATUS_WAITING_HUMAN);

        $this->assertSame(Conversation::STATUS_WAITING_HUMAN, $conversation->fresh()->status);

        $system = $conversation->messages()
            ->where('sender_type', ConversationMessage::SENDER_SYSTEM)
            ->first();
        $this->assertNotNull($system);
        $this->assertSame('admin', $system->metadata['source']);
    }

    public function test_handoff_back_to_ai_sets_ai_resumed(): void
    {
        $this->actingAsRole('manager');
        $sales = User::factory()->for($this->tenant)->role('sales')->create();
        $conversation = $this->conversationFor($sales, $sales);

        $this->postJson('/api/v1/admin/conversations/'.$conversation->id.'/handoff', [
            'to' => 'ai',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Conversation::STATUS_AI_RESUMED);

        $this->assertSame(Conversation::STATUS_AI_RESUMED, $conversation->fresh()->status);
        $this->assertNull($conversation->fresh()->assigned_to);
    }

    public function test_handoff_rejects_unknown_target(): void
    {
        $this->actingAsRole('sales');
        $sales = User::factory()->for($this->tenant)->role('sales')->create();
        $conversation = $this->conversationFor($sales, $sales);

        $this->postJson('/api/v1/admin/conversations/'.$conversation->id.'/handoff', [
            'to' => 'robot',
        ])->assertStatus(422);
    }
}
