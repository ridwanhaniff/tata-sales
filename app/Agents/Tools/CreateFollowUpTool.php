<?php

namespace App\Agents\Tools;

use App\Agents\Tools\Contracts\Tool;
use App\Models\FollowupStep;
use App\Models\Lead;
use App\Services\FollowUp\FollowUpService;
use Illuminate\Support\Arr;

/**
 * create_followup (§5 roster Follow-up Agent): jadwalkan follow-up
 * DRAFT dalam batas followup_step rule-based milik tenant. Tidak pernah
 * mengirim — status always pending, pengiriman via jalur terjadwal.
 */
class CreateFollowUpTool implements Tool
{
    public function __construct(private readonly FollowUpService $followUps) {}

    public function name(): string
    {
        return 'create_followup';
    }

    public function description(): string
    {
        return 'Jadwalkan follow-up untuk lead sesuai followup_step tertentu (step_id dari konteks). Copy yang ditulis jadi DRAFT pesan — tidak dikirim langsung.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lead_id' => ['type' => 'string', 'description' => 'id lead (dari konteks percakapan)'],
                'step_id' => ['type' => 'string', 'description' => 'id followup_step active milik tenant (dari konteks)'],
                'message' => ['type' => 'string', 'description' => 'opsional: draft copy follow-up dalam bahasa Indonesia'],
                'channel' => ['type' => 'string', 'description' => 'opsional: whatsapp (default) / email / dashboard'],
            ],
            'required' => ['lead_id', 'step_id'],
        ];
    }

    public function execute(array $arguments): array
    {
        $tenantId = app()->bound('currentTenant') ? app('currentTenant')->id : null;

        $lead = Lead::query()->find(Arr::get($arguments, 'lead_id'));
        $step = FollowupStep::query()->find(Arr::get($arguments, 'step_id'));

        if (! $lead) {
            return ['done' => false, 'reason' => 'Lead tidak ditemukan.'];
        }

        if (! $step) {
            return ['done' => false, 'reason' => 'Followup step tidak ditemukan.'];
        }

        if (($tenantId && $lead->tenant_id !== $tenantId) || $step->tenant_id !== $lead->tenant_id || $step->status !== 'active') {
            return ['done' => false, 'reason' => 'Followup step tidak valid untuk lead ini.'];
        }

        $followup = $this->followUps->scheduleFromAgent(
            $lead,
            $step,
            Arr::get($arguments, 'message') ? (string) Arr::get($arguments, 'message') : null,
            (string) Arr::get($arguments, 'channel', 'whatsapp'),
        );

        return [
            'done' => true,
            'followup_id' => $followup->id,
            'lead_id' => $lead->id,
            'status' => 'pending',
            'scheduled_at' => $followup->scheduled_at?->toIso8601String(),
            'message' => $followup->message,
        ];
    }
}
