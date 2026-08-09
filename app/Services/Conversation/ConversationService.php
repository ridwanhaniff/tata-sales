<?php

namespace App\Services\Conversation;

use App\Agents\AgentContext;
use App\Agents\CalculatorAgent;
use App\Agents\Contracts\AgentInterface;
use App\Agents\IntentAgent;
use App\Agents\ProductAgent;
use App\Agents\QualificationAgent;
use App\Models\Calculator;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\Promotion\PromotionService;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Orchestrator chat (§25, §29-32, §118):
 * pesan masuk → conversation+message tercatat → assembleContext (snapshot
 * data approved) → intent agent → rute ke agent (product/calculator/
 * qualification) → jawaban disimpan. Kalau AI gagal, intent tidak jelas,
 * keluhan, atau permintaan di luar kewenangan → handoff ke manusia
 * (status WAITING_HUMAN, jalur §62/§6).
 */
class ConversationService
{
    public const CONFIDENCE_HANDOFF = 0.70;

    public const FALLBACK_REPLY = 'Saya belum bisa memastikan informasi tersebut. Silakan saya hubungkan Anda dengan tim kami.';

    /**
     * Agent di-resolve lazy (app()) — bukan via constructor — supaya route
     * admin (handoff, reply, index) tetap bekerja walau LLM provider belum
     * dikonfigurasi; kegagalan provider cuma = fallback chat, tidak pernah
     * mematikan endpoint lain.
     */
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    private function intentAgent(): IntentAgent
    {
        return app(IntentAgent::class);
    }

    private function productAgent(): ProductAgent
    {
        return app(ProductAgent::class);
    }

    private function calculatorAgent(): CalculatorAgent
    {
        return app(CalculatorAgent::class);
    }

    private function qualificationAgent(): QualificationAgent
    {
        return app(QualificationAgent::class);
    }

    /**
     * Snapshot context percakapan (§25): customer, lead, product,
     * calculator, promo aktif — disimpan di conversations.context.
     *
     * @return array<string, mixed>
     */
    public function assembleContext(Conversation $conversation): array
    {
        $lead = $conversation->lead_id
            ? Lead::query()->find($conversation->lead_id)
            : null;

        $snapshot = [
            'assembled_at' => now()->toIso8601String(),
            'customer' => $conversation->customer ? [
                'id' => $conversation->customer->id,
                'name' => $conversation->customer->name,
                'phone' => $conversation->customer->phone,
                'location' => $conversation->customer->location,
            ] : null,
            'lead' => $lead ? [
                'id' => $lead->id,
                'status' => $lead->status,
                'estimated_value' => $lead->estimated_value,
                'product_id' => $lead->product_id,
                'campaign_id' => $lead->campaign_id,
                'source' => $lead->source,
            ] : null,
            'products' => Product::query()
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->orderByDesc('featured')
                ->orderBy('base_price')
                ->limit(8)
                ->get()
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'base_price' => (int) $p->base_price,
                    'stock_status' => $p->stock_status,
                ])
                ->values()
                ->all(),
            'calculators' => Calculator::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get()
                ->map(fn (Calculator $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'type' => $c->type,
                ])
                ->values()
                ->all(),
            'campaigns' => Campaign::query()
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                })
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
                ->map(fn (Campaign $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'utm_campaign' => $c->utm_campaign,
                    'starts_at' => $c->starts_at?->toIso8601String(),
                    'ends_at' => $c->ends_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'promotions' => app(PromotionService::class)->activeFor()
                ->map(fn (Promotion $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'description' => $p->description,
                    'discount_type' => $p->discount_type,
                    'discount_value' => (float) $p->discount_value,
                    'minimum_purchase' => $p->minimum_purchase !== null ? (float) $p->minimum_purchase : null,
                    'ends_at' => $p->ends_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
        $context['assemble'] = $snapshot;

        $conversation->forceFill([
            'context' => $context,
            'updated_at' => now(),
        ])->save();

        return $snapshot;
    }

    /**
     * Satu turn chat: simpan pesan customer → klasifikasi → agent → jawab.
     *
     * @return array{conversation_id: string, reply: string, intent: string, status: string, confidence: float}
     */
    public function chat(string $customerPhone, string $message, ?string $conversationId, Tenant $tenant): array
    {
        try {
            $phone = PhoneNormalizer::normalize($customerPhone);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['customer_phone' => [$e->getMessage()]]);
        }

        $customer = Customer::query()->where('phone', $phone)->first();

        if (! $customer) {
            $customer = Customer::create([
                'tenant_id' => $tenant->id,
                'name' => null,
                'phone' => $phone,
                'source' => 'webchat',
            ]);
        }

        $conversation = $this->resolveConversation($conversationId, $tenant, $customer);

        if (! $conversation->lead_id) {
            $activeLead = Lead::query()
                ->where('customer_id', $customer->id)
                ->where('status', '!=', 'CLOSED')
                ->latest('created_at')
                ->first();

            if ($activeLead) {
                $conversation->forceFill(['lead_id' => $activeLead->id, 'updated_at' => now()])->save();
            }
        }

        ConversationMessage::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => ConversationMessage::SENDER_CUSTOMER,
            'content' => $message,
        ]);

        $snapshot = $this->assembleContext($conversation);

        $intent = $this->detectIntent($conversation, $message);
        $intentName = $intent['intent'];
        $confidence = $intent['confidence'];

        if ($this->shouldHandOff($intentName, $confidence, $message)) {
            $this->handoff($conversation, "trigger deterministic: {$intentName} (confidence {$confidence})", 'guardrail');

            $this->saveReply($conversation, self::FALLBACK_REPLY, $intentName, 'handoff', $confidence);

            return $this->response($conversation, self::FALLBACK_REPLY, 'handoff', Conversation::STATUS_WAITING_HUMAN, $confidence);
        }

        if ($this->isPricingExceptionRequest($message)) {
            $this->handoff($conversation, 'pricing exception / negosiasi harga di luar promo', 'guardrail');

            $this->saveReply($conversation, self::FALLBACK_REPLY, $intentName, 'handoff', $confidence);

            return $this->response($conversation, self::FALLBACK_REPLY, 'handoff', Conversation::STATUS_WAITING_HUMAN, $confidence);
        }

        try {
            $agent = $this->selectAgent($intentName, $snapshot);

            $result = $agent->handle(new AgentContext(
                message: $message,
                tenant: $tenant,
                conversationId: $conversation->id,
                leadId: $snapshot['lead']['id'] ?? $conversation->lead_id,
                meta: $snapshot,
                history: $this->history($conversation),
            ));

            $reply = (string) ($result['reply'] ?? self::FALLBACK_REPLY);
            $status = Conversation::STATUS_AI_ACTIVE;
        } catch (\Throwable $e) {
            Log::warning('chat.agent_failed', [
                'conversation_id' => $conversation->id,
                'agent' => isset($agent) ? $agent->name() : 'unknown',
                'error' => $e->getMessage(),
            ]);

            $reply = self::FALLBACK_REPLY;
            $status = Conversation::STATUS_WAITING_HUMAN;
        }

        // Agent sendiri memutuskan handoff (mis. lewat tool request_human)
        if (! empty($result['handoff'])) {
            $this->handoff($conversation, (string) ($result['handoff']['reason'] ?? 'agent meminta manusia'), 'ai');

            $this->saveReply($conversation, $reply, $intentName, 'handoff', $confidence);

            return $this->response($conversation, $reply, 'handoff', Conversation::STATUS_WAITING_HUMAN, $confidence);
        }

        $conversation->forceFill([
            'status' => $status,
            'updated_at' => now(),
        ])->save();

        $this->saveReply($conversation, $reply, $intentName, $agent->name(), $confidence);

        if ($conversation->lead_id) {
            Lead::query()->whereKey($conversation->lead_id)->update(['last_activity_at' => now()]);
        }

        return $this->response($conversation, $reply, $intentName, $status, $confidence);
    }

    /**
     * Jalur tunggal handoff (§6): status WAITING_HUMAN + pesan sistem +
     * notifikasi sales/manager (owner, manager, sales yang relevan).
     *
     * @return array{conversation_id: string, status: string}
     */
    public function handoff(Conversation $conversation, string $reason, string $source = 'guardrail'): array
    {
        $conversation->forceFill([
            'status' => Conversation::STATUS_WAITING_HUMAN,
            'updated_at' => now(),
        ])->save();

        $conversation->messages()->create([
            'tenant_id' => $conversation->tenant_id,
            'sender_type' => ConversationMessage::SENDER_SYSTEM,
            'content' => 'Percakapan diteruskan ke tim manusia.',
            'intent' => 'handoff',
            'metadata' => [
                'reason' => $reason,
                'source' => $source,
            ],
        ]);

        $this->notifyHandoff($conversation, $reason);

        return [
            'conversation_id' => $conversation->id,
            'status' => Conversation::STATUS_WAITING_HUMAN,
        ];
    }

    /**
     * @return array{intent: string, confidence: float}
     */
    private function detectIntent(Conversation $conversation, string $message): array
    {
        try {
            return $this->intentAgent()->handle(new AgentContext(
                message: $message,
                conversationId: $conversation->id,
            ));
        } catch (\Throwable) {
            return ['intent' => 'unknown', 'confidence' => 0.0];
        }
    }

    private function selectAgent(string $intent, array $snapshot): AgentInterface
    {
        return match ($intent) {
            'installment', 'price' => ($snapshot['calculators'] ?? []) !== []
                ? $this->calculatorAgent()
                : $this->productAgent(),
            'purchase_intent' => $this->qualificationAgent(),
            default => $this->productAgent(),
        };
    }

    private function shouldHandOff(string $intent, float $confidence, string $message): bool
    {
        if (in_array($intent, ['complaint', 'support'], true)) {
            return true;
        }

        if (preg_match('/(agent|orang|manusia|staff|tim kamu)/iu', $message) === 1) {
            return true;
        }

        return $intent === 'unknown' && $confidence < self::CONFIDENCE_HANDOFF;
    }

    /**
     * Pricing exception (§6): permintaan diskon/nego di luar promo — AI
     * tidak boleh janji, langsung serahkan ke manusia.
     */
    private function isPricingExceptionRequest(string $message): bool
    {
        return preg_match('/(diskon lebih|korting|nego|turunin|turunkan|lebih murah|harga berapa lagi|free|gratis)/iu', $message) === 1;
    }

    private function notifyHandoff(Conversation $conversation, string $reason): void
    {
        $targetRoles = ['owner', 'manager', 'sales'];

        $userIds = User::query()
            ->where('tenant_id', $conversation->tenant_id)
            ->whereIn('role', $targetRoles)
            ->pluck('id');

        foreach ($userIds as $userId) {
            $this->notifications->notify(
                $conversation->tenant_id,
                $userId,
                'chat_handoff',
                'Percakapan butuh penanganan manusia',
                'Percakapan #'.substr($conversation->id, 0, 8)." perlu ditindaklanjuti: {$reason}",
                [
                    'conversation_id' => $conversation->id,
                    'lead_id' => $conversation->lead_id,
                    'source' => 'handoff',
                ],
                'dashboard',
            );
        }
    }

    private function resolveConversation(?string $conversationId, Tenant $tenant, Customer $customer): Conversation
    {
        if ($conversationId) {
            $found = Conversation::query()
                ->where('id', $conversationId)
                ->where('tenant_id', $tenant->id)
                ->where(function ($q) use ($customer) {
                    $q->where('customer_id', $customer->id)
                        ->orWhereNull('customer_id');
                })
                ->first();

            if ($found) {
                if ($found->customer_id === null) {
                    $found->forceFill(['customer_id' => $customer->id, 'updated_at' => now()])->save();
                }

                return $found;
            }
        }

        return Conversation::query()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->where('channel', 'webchat')
            ->latest('updated_at')
            ->first()
            ?? Conversation::create([
                'tenant_id' => $tenant->id,
                'customer_id' => $customer->id,
                'channel' => 'webchat',
                'status' => Conversation::STATUS_AI_ACTIVE,
                'context' => [],
            ]);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function history(Conversation $conversation): array
    {
        return $conversation->messages()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->reverse()
            ->map(fn (ConversationMessage $m) => [
                'role' => $m->sender_type === ConversationMessage::SENDER_CUSTOMER ? 'user' : 'assistant',
                'content' => $m->content,
            ])
            ->values()
            ->all();
    }

    private function saveReply(Conversation $conversation, string $reply, string $intent, string $agent, float $confidence): void
    {
        ConversationMessage::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => ConversationMessage::SENDER_AI,
            'content' => $reply,
            'intent' => $intent,
            'metadata' => [
                'agent' => $agent,
                'confidence' => $confidence,
            ],
        ]);
    }

    private function response(Conversation $conversation, string $reply, string $intent, string $status, float $confidence): array
    {
        return [
            'conversation_id' => $conversation->id,
            'reply' => $reply,
            'intent' => $intent,
            'status' => $status,
            'confidence' => $confidence,
        ];
    }
}
