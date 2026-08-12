<?php

namespace Tests\Feature\Console;

use App\Agents\Contracts\LLMProvider;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Tenant;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FakeLLMProvider;
use Tests\TestCase;

class TataChatCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->seed([PipelineStageSeeder::class]);
    }

    public function test_one_shot_message_runs_full_pipeline_and_prints_reply(): void
    {
        app()->instance(LLMProvider::class, new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"availability","confidence":0.95}'),
            FakeLLMProvider::text('FRONX tersedia di showroom terdekat.'),
        ]));

        $this->artisan('tata:chat', [
            'tenant' => $this->tenant->id,
            '--message' => 'Ada FRONX?',
        ])
            ->expectsOutputToContain('availability')
            ->expectsOutputToContain('FRONX tersedia di showroom terdekat.')
            ->assertExitCode(0);

        $customer = Customer::query()->firstOrFail();
        $this->assertSame('6280000000000', $customer->phone);

        $conversation = Conversation::query()->firstOrFail();
        $this->assertSame('webchat', $conversation->channel);
        $this->assertSame(2, ConversationMessage::where('conversation_id', $conversation->id)->count());
    }

    public function test_custom_phone_option_is_used(): void
    {
        app()->instance(LLMProvider::class, new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"availability","confidence":0.95}'),
            FakeLLMProvider::text('Tersedia.'),
        ]));

        $this->artisan('tata:chat', [
            'tenant' => $this->tenant->id,
            '--phone' => '628111122223333',
            '--message' => 'Ada stok?',
        ])->assertExitCode(0);

        $this->assertSame('628111122223333', Customer::query()->firstOrFail()->phone);
    }

    public function test_unknown_tenant_fails(): void
    {
        $this->artisan('tata:chat', [
            'tenant' => (string) Str::uuid(),
            '--message' => 'Halo',
        ])->assertExitCode(1);
    }
}
