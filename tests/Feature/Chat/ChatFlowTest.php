<?php

namespace Tests\Feature\Chat;

use App\Agents\Contracts\LLMProvider;
use App\Models\AiAgentLog;
use App\Models\Calculator;
use App\Models\CalculatorSession;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Notification;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Promotion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Conversation\ConversationService;
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

    public function test_complaint_goes_to_waiting_human_via_handoff_agent(): void
    {
        $fake = new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"complaint","confidence":0.99}'),
            fn () => FakeLLMProvider::toolCall('request_human', [
                'conversation_id' => Conversation::query()->first()->id,
                'reason' => 'keluhan proses lambat',
            ]),
            FakeLLMProvider::text('Baik, saya hubungkan Anda dengan tim kami.'),
        ]);
        $this->withFakeLLM($fake);

        $response = $this->chatRequest(['customer_phone' => '081111111111', 'message' => 'prosesnya lama sekali']);
        $response->assertOk();
        $response->assertJsonPath('data.status', Conversation::STATUS_WAITING_HUMAN);
        $response->assertJsonPath('data.intent', 'handoff');

        $conversation = Conversation::query()->first();
        $this->assertSame(Conversation::STATUS_WAITING_HUMAN, $conversation->status);

        // Jalur: intent → Handoff Agent (request_human) → balasan AI disusun LLM
        $this->assertSame(3, $fake->generateCalls);
        $this->assertDatabaseHas('ai_agent_logs', [
            'agent' => 'handoff',
            'tool_called' => 'request_human',
            'status' => AiAgentLog::STATUS_SUCCESS,
        ]);

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
            fn () => FakeLLMProvider::toolCall('request_human', [
                'conversation_id' => Conversation::query()->first()->id,
                'reason' => 'pesan tidak jelas',
            ]),
            FakeLLMProvider::text('Baik, saya hubungkan Anda dengan tim kami.'),
        ]);
        $this->withFakeLLM($fake);

        $response = $this->chatRequest(['customer_phone' => '081222222222', 'message' => 'laruih wks owo!']);
        $response->assertOk();
        $response->assertJsonPath('data.status', Conversation::STATUS_WAITING_HUMAN);
        $response->assertJsonPath('data.intent', 'handoff');
    }

    public function test_guardrail_handoff_falls_back_deterministic_when_agent_fails(): void
    {
        // Handoff Agent gagal (LLM tidak punya step) → handoff deterministik
        // + FALLBACK_REPLY tetap berjalan; percakapan tidak pernah macet.
        $fake = new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"complaint","confidence":0.99}'),
        ]);
        $this->withFakeLLM($fake);

        $response = $this->chatRequest(['customer_phone' => '081555555555', 'message' => 'prosesnya lama sekali']);
        $response->assertOk();
        $response->assertJsonPath('data.status', Conversation::STATUS_WAITING_HUMAN);
        $response->assertJsonPath('data.intent', 'handoff');
        $response->assertJsonPath('data.reply', ConversationService::FALLBACK_REPLY);

        $conversation = Conversation::query()->first();
        $this->assertSame(Conversation::STATUS_WAITING_HUMAN, $conversation->status);

        $system = $conversation->messages()
            ->where('sender_type', ConversationMessage::SENDER_SYSTEM)
            ->first();
        $this->assertSame('guardrail', $system->metadata['source']);

        $aiMessage = $conversation->messages()
            ->where('sender_type', ConversationMessage::SENDER_AI)
            ->first();
        $this->assertSame(ConversationService::FALLBACK_REPLY, $aiMessage->content);
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
            fn () => FakeLLMProvider::toolCall('request_human', [
                'conversation_id' => Conversation::query()->first()->id,
                'reason' => 'customer minta nego harga',
            ]),
            FakeLLMProvider::text('Baik, saya hubungkan Anda dengan tim kami.'),
        ]);
        $this->withFakeLLM($fake);

        // AI tidak boleh nego harga sendiri — diskon di luar promo = handoff
        $response = $this->chatRequest(['customer_phone' => '081444444444', 'message' => 'Harga nya bisa di nego ga? turunin dikit']);

        $response->assertOk();
        $response->assertJsonPath('data.status', Conversation::STATUS_WAITING_HUMAN);
        $response->assertJsonPath('data.intent', 'handoff');

        $this->assertSame(3, $fake->generateCalls);
    }

    public function test_recommendation_intent_routes_to_recommendation_agent(): void
    {
        $this->makeProduct();
        Promotion::factory()->for($this->tenant)->create(['name' => 'Diskon Agustus']);

        $fake = new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"recommendation","confidence":0.92}'),
            FakeLLMProvider::toolCall('search_products', ['query' => 'FRONX', 'budget_max' => 250_000_000]),
            FakeLLMProvider::toolCall('get_promotion', []),
            FakeLLMProvider::text('Rekomendasi: FRONX GLX dengan promo Diskon Agustus.'),
        ]);
        $this->withFakeLLM($fake);

        $response = $this->chatRequest([
            'customer_phone' => '081666666666',
            'message' => 'Rekomendasikan mobil budget 250 juta untuk saya.',
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.intent', 'recommendation');
        $response->assertJsonPath('data.status', Conversation::STATUS_AI_ACTIVE);
        $response->assertJsonPath('data.reply', 'Rekomendasi: FRONX GLX dengan promo Diskon Agustus.');

        $this->assertDatabaseHas('ai_agent_logs', [
            'agent' => 'recommendation',
            'tool_called' => 'search_products',
            'status' => AiAgentLog::STATUS_SUCCESS,
        ]);
        $this->assertDatabaseHas('ai_agent_logs', [
            'agent' => 'recommendation',
            'tool_called' => 'get_promotion',
            'status' => AiAgentLog::STATUS_SUCCESS,
        ]);
    }

    public function test_purchase_intent_qualified_routes_to_recommendation_agent(): void
    {
        $product = $this->makeProduct();
        $phone = '6281777777777';
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => $phone]);
        $lead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'status' => 'QUALIFIED',
            'estimated_value' => 200_000_000,
            'product_id' => $product->id,
        ]);
        Conversation::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'lead_id' => $lead->id,
            'status' => Conversation::STATUS_AI_ACTIVE,
            'channel' => 'webchat',
            'context' => [],
        ]);

        $fake = new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"purchase_intent","confidence":0.93}'),
            FakeLLMProvider::toolCall('search_products', ['query' => 'FRONX', 'budget_max' => 200_000_000]),
            FakeLLMProvider::text('Rekomendasi saya: FRONX GLX.'),
        ]);
        $this->withFakeLLM($fake);

        $response = $this->chatRequest([
            'customer_phone' => $phone,
            'message' => 'Saya tertarik dengan FRONX, rekomendasikan yang cocok.',
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.intent', 'purchase_intent');
        $response->assertJsonPath('data.status', Conversation::STATUS_AI_ACTIVE);

        // Lead QUALIFIED + budget + produk → rekomendasi, bukan qualification lagi
        $this->assertDatabaseHas('ai_agent_logs', [
            'agent' => 'recommendation',
            'tool_called' => 'search_products',
            'status' => AiAgentLog::STATUS_SUCCESS,
        ]);
        $this->assertDatabaseMissing('ai_agent_logs', [
            'agent' => 'qualification',
        ]);
    }

    public function test_full_chain_calculator_create_lead_assign_sales(): void
    {
        $this->makeProduct();
        $calculator = Calculator::factory()->for($this->tenant)->credit()->create();

        $phone = '6281555555555';
        $customer = Customer::factory()->for($this->tenant)->create([
            'phone' => $phone,
            'consent_marketing' => true,
        ]);
        $sales = User::factory()->for($this->tenant)->role(User::ROLE_SALES)->create(['status' => 'active']);

        $fake = new FakeLLMProvider([
            FakeLLMProvider::text('{"intent":"installment","confidence":0.95}'),
            FakeLLMProvider::toolCall('calculate', [
                'calculator_id' => $calculator->id,
                'inputs' => ['price' => 249_500_000, 'dp' => 50_000_000, 'tenor' => 48, 'interest' => 8],
            ]),
            fn () => FakeLLMProvider::toolCall('create_lead', [
                'calculator_session_id' => CalculatorSession::query()->latest('created_at')->value('id'),
            ]),
            fn () => FakeLLMProvider::toolCall('assign_sales', [
                'lead_id' => Lead::query()->latest('created_at')->value('id'),
            ]),
            FakeLLMProvider::text('Simulasi selesai. Tim kami akan segera menghubungi Anda.'),
        ]);
        $this->withFakeLLM($fake);

        $response = $this->chatRequest([
            'customer_phone' => $phone,
            'message' => 'Saya tertarik, tolong dibantu proses cicilannya.',
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.intent', 'installment');
        $response->assertJsonPath('data.status', Conversation::STATUS_AI_ACTIVE);

        // Chain penuh (docs/08 langkah 6): satu pesan → lead baru dengan
        // lead_events berurutan calculator_completed → lead_created → sales_assigned.
        $lead = Lead::query()->firstOrFail();
        $this->assertSame('chat', $lead->source);
        $this->assertSame('NEW', $lead->status);
        $this->assertSame($customer->id, $lead->customer_id);
        $this->assertSame($sales->id, $lead->assigned_to);
        $this->assertSame($lead->id, Conversation::firstOrFail()->lead_id);

        $events = LeadEvent::query()
            ->where('lead_id', $lead->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->pluck('event_type')
            ->unique()
            ->values()
            ->all();
        $this->assertSame(['calculator_completed', 'lead_created', 'sales_assigned'], $events);

        // Sesi kalkulator tertaut ke lead
        $session = CalculatorSession::query()->first();
        $this->assertSame($lead->id, $session->lead_id);

        // Sales dinotifikasi
        $this->assertSame(1, Notification::where('user_id', $sales->id)->where('type', 'new_lead')->count());

        // Semua tool call tercatat di ai_agent_logs
        $this->assertSame(1, AiAgentLog::where('tool_called', 'calculate')->where('status', AiAgentLog::STATUS_SUCCESS)->count());
        $this->assertSame(1, AiAgentLog::where('tool_called', 'create_lead')->where('status', AiAgentLog::STATUS_SUCCESS)->count());
        $this->assertSame(1, AiAgentLog::where('tool_called', 'assign_sales')->where('status', AiAgentLog::STATUS_SUCCESS)->count());
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->withFakeLLM(new FakeLLMProvider);

        $response = $this->chatRequest(['customer_phone' => 'abc', 'message' => 'halo']);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }
}
