<?php

namespace App\Agents\Tools;

use App\Agents\Tools\Contracts\Tool;
use App\Models\Conversation;
use App\Services\Conversation\ConversationService;
use Illuminate\Support\Arr;

/**
 * request_human (§5 roster Handoff Agent): menyerahkan percakapan ke
 * manusia. status diganti via ConversationService::handoff() — jalur
 * tunggal yang juga dipakai trigger deterministic & admin.
 *
 * note: ConversationService di-resolve lazy (closure) supaya tidak ada
 * circular dependency dengan agent yang menyandang tool ini.
 */
class RequestHumanTool implements Tool
{
    public function name(): string
    {
        return 'request_human';
    }

    public function description(): string
    {
        return 'Serahkan percakapan ke tim manusia. HANYA dipakai kalau customer minta manusia, menyampaikan keluhan, atau meminta hal di luar kewenangan. Setelah handoff, berhenti membalas sebagai AI.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'conversation_id' => ['type' => 'string', 'description' => 'id percakapan (dari konteks)'],
                'reason' => ['type' => 'string', 'description' => 'alasan singkat kenapa perlu manusia'],
            ],
            'required' => ['reason'],
        ];
    }

    public function execute(array $arguments): array
    {
        $tenantId = app()->bound('currentTenant') ? app('currentTenant')->id : null;

        $conversation = Conversation::query()
            ->find(Arr::get($arguments, 'conversation_id'));

        if (! $conversation || ($tenantId && $conversation->tenant_id !== $tenantId)) {
            return ['done' => false, 'reason' => 'Percakapan tidak ditemukan.'];
        }

        $reason = trim((string) Arr::get($arguments, 'reason', 'customer minta manusia'));

        $result = app(ConversationService::class)->handoff($conversation, mb_substr($reason, 0, 255), 'ai');

        return [
            'done' => true,
            'conversation_id' => $result['conversation_id'],
            'status' => $result['status'],
            'reason' => $reason,
        ];
    }
}
