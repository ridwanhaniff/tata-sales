<?php

namespace App\Agents;

use App\Agents\Tools\RequestHumanTool;
use App\Agents\Tools\UpdateLeadTool;
use App\Agents\Values\LLMResponse;
use App\Models\AiAgentLog;
use App\Services\Lead\LeadService;

/**
 * Qualification Agent (§5/§29) — mengumpulkan budget, timeline, lokasi,
 * produk diminati, dan niat beli. Data masuk CRM hanya lewat tool
 * update_lead (whitelist field). Tidak pernah memaksa customer dan
 * tidak pernah menyimpulkan nilai yang tidak diucapkan.
 */
class QualificationAgent extends Agent
{
    public function name(): string
    {
        return 'qualification';
    }

    public function tools(): array
    {
        return [
            new UpdateLeadTool(app(LeadService::class)),
            new RequestHumanTool,
        ];
    }

    protected function systemPrompt(AgentContext $context): string
    {
        return <<<'PROMPT'
Kamu adalah asisten kualifikasi penjualan. Tugas: kumpulkan informasi kualifikasi customer.

ATURAN WAJIB:
- Lead_id ada di konteks percakapan. Kalau tidak ada, jangan dipaksakan memakai tool.
- Panggil tool update_lead setiap customer menyebut: budget (estimated_value), produk diminati (product_id), lokasi (customer_location), atau timeline.
- Hanya catat yang benar-benar diucapkan customer — jangan menyimpulkan nilai atau menekan jawaban.
- Jangan memaksa; kalau customer enggan, berhenti dengan ramah dan tawarkan bantuan tim.
- Jawab dalam bahasa Indonesia, ringkas, tanpa markdown.
PROMPT;
    }

    protected function finalize(AgentContext $context, array $messages, array $toolResults, LLMResponse $final): array
    {
        $update = collect($toolResults)->firstWhere('tool', 'update_lead');

        return [
            'reply' => $final->content,
            'updated_fields' => $update && $update->status === AiAgentLog::STATUS_SUCCESS
                ? ($update->output['applied_fields'] ?? null)
                : null,
        ];
    }
}
