<?php

namespace App\Agents;

use App\Agents\Tools\CreateFollowUpTool;
use App\Agents\Values\LLMResponse;
use App\Models\AiAgentLog;
use App\Services\FollowUp\FollowUpService;

/**
 * Follow-up Agent (§5/§28): menulis copy follow-up DRAFT dalam batas
 * followup_steps rule-based. Tidak pernah mengirim pesan bebas — tool
 * create_followup selalu menghasilkan status 'pending'.
 */
class FollowupAgent extends Agent
{
    public function name(): string
    {
        return 'followup';
    }

    public function tools(): array
    {
        return [
            new CreateFollowUpTool(app(FollowUpService::class)),
        ];
    }

    protected function systemPrompt(AgentContext $context): string
    {
        return <<<'PROMPT'
Kamu adalah copywriter follow-up penjualan. Tugas: menulis draft pesan tindak lanjut sesuai konteks lead dan jadwal rule-based.

ATURAN WAJIB:
- Wajib panggil tool create_followup; copy follow-up yang kamu tulis masuk ke parameter message.
- Tidak boleh mengirim pesan sendiri — tool hanya membuat draft berstatus pending.
- Gunakan bahasa Indonesia yang ringkas dan hangat, sesuaikan status lead serta histori percakapan.
- kalau konteks tidak memberikan lead_id/step_id dari jadwal aktif → jangan menebak, tanya atau berhenti.
- Jangan tulis klaim harga/diskon yang tidak ada di konteks data approved.
PROMPT;
    }

    protected function finalize(AgentContext $context, array $messages, array $toolResults, LLMResponse $final): array
    {
        $created = collect($toolResults)->firstWhere('tool', 'create_followup');

        return [
            'reply' => $final->content,
            'followup_id' => $created && $created->status === AiAgentLog::STATUS_SUCCESS
                ? ($created->output['followup_id'] ?? null)
                : null,
            'scheduled_at' => $created ? ($created->output['scheduled_at'] ?? null) : null,
        ];
    }
}
