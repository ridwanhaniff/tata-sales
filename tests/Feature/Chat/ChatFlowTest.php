<?php

namespace Tests\Feature\Chat;

use App\Agents\Contracts\LLMProvider;
use App\Models\AiAgentLog;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Promotion;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeLLMProvider;
use Tests\TestCase;

class ChatFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
    }

    private function withFakeLLM(FakeLLMProvider $fake): void
    {
        app()->instance(LLMProvider::class, $fake);
    }

    private function makeProduct(): Product
    {
        $category = ProductCategory::factory()->for($this->tenant)->create(['name' => 'Mobil']);
        $product = Product::factory()->for($this->tenant)->create([
            'name' => 'FRONX GLX',
            'base_price' => 249_500_000,
            'published_at' => now(),
            'category_id' => $category->id,
        ]);
        $product->attributes()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_key' => 'cc',
            'attribute_value' => '1500',
            'attribute_type' => 'number',
        ]);

        return $product;
    }

    private function chatRequest(array $payload): TestResponse
    {
        return $this->postJson('/api/v1/chat/message', $payload, ['X-Tenant-ID' => $this->tenant->id]);
    }

    public function test_flow_routes_intent_to_product_agent_and_stores_everything(): void
    {
        $this->makeProduct();
        $campaign = Campaign::factory()->for($this->tenant)->create(['name' => 'Agustus Ceria']);
        Promotion::factory()->for($this->tenant)->create(['name' => 'Diskon Agustus']);

        $fake = new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"availability","confidence":0.95}'),
            FakeLLMProvider::toolCall('search_products', ['query' => 'FRONX']),
            FakeLLMProvider::text('FRONX GLX tersedia di showroom terdekat.'),
        ]);
        $this->withFakeLLM($fake);

        $response = $this->chatRequest(['customer_phone' => '081234567890', 'message' => 'Ada FRONX glx?']);
        $response->assertOk();
        $response->assertJsonPath('data.intent', 'availability');
        $response->assertJsonPath('data.status', Conversation::STATUS_AI_ACTIVE);
        $response->assertJsonPath('data.reply', 'FRONX GLX tersedia di showroom terdekat.');
        $response->assertJsonStructure(['data' => ['conversation_id', 'reply', 'intent', 'status', 'confidence']]);

        $conversation = Conversation::query()->first();
        $this->assertNotNull($conversation);
        $this->assertSame($conversation->id, $response->json('data.conversation_id'));
        $this->assertSame(Conversation::STATUS_AI_ACTIVE, $conversation->status);
        $this->assertSame('webchat', $conversation->channel);

        $customer = Customer::where('phone', '6281234567890')->first();
        $this->assertNotNull($customer);
        $this->assertSame($customer->id, $conversation->customer_id);

        // Snapshot context tersimpan (§25): customer/product/promo/calculator/lead/campaign
        $assemble = (array) $conversation->context;
        $this->assertArrayHasKey('assemble', $assemble);
        $this->assertSame('FRONX GLX', $assemble['assemble']['products'][0]['name']);
        $this->assertSame('Diskon Agustus', $assemble['assemble']['promotions'][0]['name']);
        $this->assertSame($campaign->id, $assemble['assemble']['campaigns'][0]['id']);
        $this->assertSame('Agustus Ceria', $assemble['assemble']['campaigns'][0]['name']);

        // Pesan customer + AI tercatat
        $this->assertSame(2, ConversationMessage::where('conversation_id', $conversation->id)->count());

        // Log intent + agent product
        $intentLog = AiAgentLog::where('agent', 'intent')->first();
        $this->assertNotNull($intentLog);
        $this->assertSame('availability', $intentLog->output['intent']);

        $productLog = AiAgentLog::where('agent', 'product')->where('tool_called', 'search_products')->first();
        $this->assertNotNull($productLog);
        $this->assertSame(AiAgentLog::STATUS_SUCCESS, $productLog->status);
    }

    public function test_complaint_goes_to_waiting_human_without_llm_reply(): void
    {
        $fake = new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"complaint","confidence":0.99}'),
        ]);
        $this->withFakeLLM($fake);

        $response = $this->chatRequest(['customer_phone' => '081111111111', 'message' => 'prosesnya lama sekali']);
        $response->assertOk();
        $response->assertJsonPath('data.status', Conversation::STATUS_WAITING_HUMAN);
        $response->assertJsonPath('data.intent', 'handoff');

        $conversation = Conversation::query()->first();
        $this->assertSame(Conversation::STATUS_WAITING_HUMAN, $conversation->status);

        // Tidak ada jawaban AI; hanya fallback yang jujur
        $this->assertSame(1, $fake->generateCalls);
        $aiMessage = ConversationMessage::where('conversation_id', $conversation->id)
            ->where('sender_type', 'ai')->first();
        $this->assertNotNull($aiMessage);
        $this->assertStringContainsString('tim kami', $aiMessage->content);
        $this->assertSame('handoff', $aiMessage->metadata['agent']);
    }

    public function test_unknown_low_confidence_falls_back_to_human(): void
    {
        $fake = new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"unknown","confidence":0.2}'),
        ]);
        $this->withFakeLLM($fake);

        $response = $this->chatRequest(['customer_phone' => '081222222222', 'message' => 'laruih wks owo!']);
        $response->assertOk();
        $response->assertJsonPath('data.status', Conversation::STATUS_WAITING_HUMAN);
        $response->assertJsonPath('data.intent', 'handoff');
    }

    public function test_messages_reuse_active_conversation_for_same_phone(): void
    {
        $fake = new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"price","confidence":0.9}'),
            FakeLLMProvider::toolCall('search_products', ['query' => 'FRONX']),
            FakeLLMProvider::text('Jawaban pertama.'),
            FakeLLMProvider::text('{"intent":"price","confidence":0.9}'),
            FakeLLMProvider::toolCall('search_products', ['query' => 'FRONX GLX']),
            FakeLLMProvider::text('Jawaban kedua.'),
        ]);
        $this->withFakeLLM($fake);

        $this->chatRequest(['customer_phone' => '081333333333', 'message' => 'Harga FRONX?'])->assertOk();
        $response = $this->chatRequest(['customer_phone' => '081333333333', 'message' => 'FRONX GLX?']);

        $response->assertOk();
        $conversation = Conversation::query()->first();
        $this->assertSame(1, Conversation::where('id', $conversation->id)->count());
        $this->assertSame(4, ConversationMessage::where('conversation_id', $conversation->id)->count());
    }

    public function test_purchase_intent_routes_to_qualification_agent_which_updates_lead(): void
    {
        $phone = '6281234567890';
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => $phone]);
        $lead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'status' => 'QUALIFIED',
        ]);

        $fake = new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"purchase_intent","confidence":0.91}'),
            FakeLLMProvider::toolCall('update_lead', [
                'lead_id' => $lead->id,
                'fields' => ['estimated_value' => 180_000_000, 'customer_location' => 'Bekasi'],
            ]),
            FakeLLMProvider::text('Saya catat budget Anda 180 juta.'),
        ]);
        $this->withFakeLLM($fake);

        $response = $this->chatRequest([
            'customer_phone' => $phone,
            'message' => 'Keperluan saya sampai dengan budget Rp180 juta dan lokasi Bekasi.',
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.intent', 'purchase_intent');

        $lead->refresh();
        $this->assertSame('180000000.00', $lead->estimated_value);
        $this->assertSame('Bekasi', $lead->customer->location);

        $log = AiAgentLog::where('agent', 'qualification')->where('tool_called', 'update_lead')->first();
        $this->assertNotNull($log);
        $this->assertSame($lead->id, $log->lead_id);
        $this->assertSame(AiAgentLog::STATUS_SUCCESS, $log->status);
    }

    public function test_pricing_exception_goes_directly_to_human(): void
    {
        $fake = new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"price","confidence":0.9}'),
        ]);
        $this->withFakeLLM($fake);

        // AI tidak boleh nego harga sendiri — diskon di luar promo = handoff
        $response = $this->chatRequest(['customer_phone' => '081444444444', 'message' => 'Harga nya bisa di nego ga? turunin dikit']);

        $response->assertOk();
        $response->assertJsonPath('data.status', Conversation::STATUS_WAITING_HUMAN);
        $response->assertJsonPath('data.intent', 'handoff');

        $this->assertSame(1, $fake->generateCalls);
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->withFakeLLM(new FakeLLMProvider);

        $response = $this->chatRequest(['customer_phone' => 'abc', 'message' => 'halo']);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }
}
