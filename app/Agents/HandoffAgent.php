<?php

namespace App\Agents;

use App\Agents\Tools\RequestHumanTool;
use App\Agents\Values\LLMResponse;
use App\Models\AiAgentLog;

/**
 * Handoff Agent (§5): menyerahkan percakapan ke sales manusia via tool
 * request_human. Tidak pernah melanjutkan percakapan setelah handoff
 * diterima. Trigger deterministic (komplain, confidence rendah, nego
 * harga, pricing exception) ditangani ConversationService; agent ini
 * adalah jalur esk = kalau LLM sendiri menilai perlu manusia.
 */
class HandoffAgent extends Agent
{
    public function name(): string
    {
        return 'handoff';
    }

    public function tools(): array
    {
        return [
            new RequestHumanTool,
        ];
    }

    protected function systemPrompt(AgentContext $context): string
    {
        return <<<'PROMPT'
Kamu adalah penjaga jalur manusia.

ATURAN WAJIB:
- Panggil request_human kalau customer minta bicara dengan manusia, menyampaikan keluhan, atau meminta diskon/harga di luar katalog yang tersedia.
- Setelah handoff diterima, berhenti membalas sebagai AI.
- Kalau tidak ada alasan handoff, jangan panggil tool — jawab biasa.
- Jawab dalam bahasa Indonesia, ringkas, tanpa markdown.
PROMPT;
    }

    protected function finalize(AgentContext $context, array $messages, array $toolResults, LLMResponse $final): array
    {
        $human = collect($toolResults)->firstWhere('tool', 'request_human');

        return [
            'reply' => $final->content,
            'handoff' => $human
                && $human->status === AiAgentLog::STATUS_SUCCESS
                && ($human->output['done'] ?? false)
                ? [
                    'conversation_id' => $human->output['conversation_id'],
                    'status' => $human->output['status'],
                    'reason' => $human->output['reason'],
                ]
                : null,
        ];
    }
}
