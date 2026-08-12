<?php

namespace App\Agents;

use App\Agents\Contracts\LLMProvider;
use App\Agents\Support\AgentTenant;
use App\Agents\Support\ToolExecutor;
use App\Agents\Values\LLMResponse;
use App\Models\AiAgentLog;

/**
 * Intent Agent — klasifikasi murni (§29): intent + confidence.
 * Tidak pernah menjawab customer; hasil dipakai untuk memilih
 * agent/tool berikutnya di chain (Sprint 10).
 *
 * Label intent: price, availability, promotion, installment, location,
 * specification, comparison, purchase_intent, recommendation, support,
 * complaint.
 */
class IntentAgent extends Agent
{
    public function __construct(LLMProvider $llm, ToolExecutor $executor)
    {
        parent::__construct($llm, $executor);
    }

    public function name(): string
    {
        return 'intent';
    }

    public function tools(): array
    {
        return [];
    }

    protected function systemPrompt(AgentContext $context): string
    {
        return <<<'PROMPT'
Kamu adalah pengelompokkan intent percakapan penjualan. Balas HANYA satu objek JSON tanpa teks lain:
{"intent": "<label>", "confidence": 0.0-1.0}

Label yang tersedia: price, availability, installment, promotion, location, specification, comparison, purchase_intent, recommendation, support, complaint.
- harga/cicilan/total → price|installment
- ketersediaan stok → availability
- diskon/promo → promotion
- spesifikasi/beda dengan produk lain → specification|comparison
- lokasi/showroom → location
- keluhan/butuh manusia → complaint|support
- tanda mau beli eksplisit → purchase_intent
- minta rekomendasi/proposal produk sesuai budget → recommendation
Kalau tidak yakin, confidence rendah.
PROMPT;

    }

    protected function finalize(AgentContext $context, array $messages, array $toolResults, LLMResponse $final): array
    {
        $parsed = json_decode(trim($final->content), true);
        $intent = is_array($parsed) ? (string) ($parsed['intent'] ?? 'unknown') : 'unknown';
        $confidence = is_array($parsed) ? (float) ($parsed['confidence'] ?? 0.0) : 0.0;

        if (! in_array($intent, $this->labels(), true)) {
            $intent = 'unknown';
            $confidence = 0.0;
        }

        AiAgentLog::create([
            'tenant_id' => AgentTenant::resolve($context),
            'conversation_id' => $context->conversationId,
            'lead_id' => $context->leadId,
            'agent' => $this->name(),
            'tool_called' => null,
            'input' => ['message' => $context->message],
            'output' => ['intent' => $intent, 'confidence' => $confidence],
            'confidence' => $confidence,
            'status' => AiAgentLog::STATUS_SUCCESS,
            'latency_ms' => $final->latencyMs,
            'created_at' => now(),
        ]);

        return [
            'intent' => $intent,
            'confidence' => round(min(max($confidence, 0.0), 1.0), 3),
        ];
    }

    private function labels(): array
    {
        return [
            'price', 'availability', 'installment', 'promotion', 'location',
            'specification', 'comparison', 'purchase_intent', 'recommendation',
            'support', 'complaint', 'unknown',
        ];
    }
}
