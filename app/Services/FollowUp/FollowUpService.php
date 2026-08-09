<?php

namespace App\Services\FollowUp;

use App\Models\Followup;
use App\Models\FollowupStep;
use App\Models\Lead;
use App\Services\Notification\NotificationService;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\ConditionEvaluator;

class FollowUpService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly WhatsAppService $whatsapp,
    ) {}

    /**
     * Rule engine (§28, §40): jadwalkan follow-up dari followup_steps aktif
     * yang trigger_event-nya cocok dan condition-nya lolos.
     *
     * @return list<Followup>
     */
    public function scheduleFor(Lead $lead, string $event): array
    {
        $steps = FollowupStep::query()
            ->where('tenant_id', $lead->tenant_id)
            ->where('status', 'active')
            ->where('trigger_event', $event)
            ->orderBy('sort_order')
            ->get();

        $context = $this->contextFor($lead);

        $created = [];

        foreach ($steps as $step) {
            if (! ConditionEvaluator::passes($context, $step->condition)) {
                continue;
            }

            $created[] = $this->createFollowup($step, $lead);
        }

        return $created;
    }

    public function createFollowup(FollowupStep $step, Lead $lead): Followup
    {
        $message = $this->interpolate($step->message, $lead);

        return Followup::create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to,
            'status' => 'pending',
            'channel' => 'whatsapp',
            'scheduled_at' => now()->addMinutes(max(0, (int) $step->delay_minutes)),
            'message' => $message ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * Kirim follow-up yang sudah jatuh tempo (status pending, scheduled_at <= now).
     *
     * @return int jumlah follow-up yang dikirim
     */
    public function sendDue(): int
    {
        $due = Followup::query()
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->limit(500)
            ->get();

        $sent = 0;

        foreach ($due as $followup) {
            $wasSent = $this->send($followup);
            $sent += $wasSent ? 1 : 0;
        }

        return $sent;
    }

    public function send(Followup $followup): bool
    {
        if ($followup->status !== 'pending') {
            return false;
        }

        try {
            $followup->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
            ])->save();

            // WhatsApp Business API (§25): channel whatsapp dikirim via
            // provider aktif (echo di dev). Nomor tidak ada → dilewati,
            // notifikasi tetap dikirim ke sales.
            if ($followup->channel === 'whatsapp' && $followup->lead && $followup->lead->customer?->phone) {
                $this->whatsapp->send($followup->lead, (string) $followup->message, followup: $followup);
            }

            if ($followup->assigned_to) {
                $this->notifications->notify(
                    $followup->tenant_id,
                    $followup->assigned_to,
                    'followup_sent',
                    'Follow-up terkirim',
                    'Follow-up untuk lead '.($followup?->lead?->customer?->name ?? '-').' sudah terkirim.',
                    [
                        'followup_id' => $followup->id,
                        'lead_id' => $followup->lead_id,
                        'channel' => $followup->channel,
                    ]
                );
            }

            return true;
        } catch (\Throwable) {
            $followup->forceFill(['status' => 'failed'])->save();

            return false;
        }
    }

    /**
     * Interpolasi placeholder {customer_name}, {product_name} di pesan rule.
     */
    public function interpolate(?string $message, Lead $lead): ?string
    {
        if (! $message) {
            return null;
        }

        return str_replace(
            ['{customer_name}', '{product_name}', '{lead_id}'],
            [
                $lead->customer?->name ?? 'Customer',
                $lead->product?->name ?? 'produk',
                $lead->id,
            ],
            (string) $message
        );
    }

    /**
     * Follow-up dari Follow-up Agent (§5/§28): menulis copy DRAFT dalam
     * batas followup_step rule-based. Tidak pernah mengirim langsung —
     * status tetap pending, pengiriman tetap lewat jalur terjadwal.
     *
     * @param  FollowupStep  $step  step aktif milik tenant yang sama
     * @param  string|null  $draftCopy  copy tulis agent (opsional); kalau kosong dipakai template step
     */
    public function scheduleFromAgent(Lead $lead, FollowupStep $step, ?string $draftCopy = null, string $channel = 'whatsapp'): Followup
    {
        if ($step->tenant_id !== $lead->tenant_id || $step->status !== 'active') {
            throw new \InvalidArgumentException('Followup step tidak valid untuk lead ini.');
        }

        $message = $this->interpolate(trim((string) $draftCopy), $lead) ?: $this->interpolate($step->message ?? '', $lead);

        if ($message === '' || $message === null) {
            $message = 'Halo {customer_name}, kami menindaklanjuti percakapan kita sebelumnya.';
            $message = $this->interpolate($message, $lead);
        }

        return Followup::create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to,
            'status' => 'pending',
            'channel' => in_array($channel, ['whatsapp', 'email', 'dashboard'], true) ? $channel : 'whatsapp',
            'scheduled_at' => now()->addMinutes(max(0, (int) $step->delay_minutes)),
            'message' => mb_substr((string) $message, 0, 2000),
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function contextFor(Lead $lead): array
    {
        return [
            'score' => $lead->score,
            'temperature' => $lead->temperature,
            'status' => $lead->status,
            'source' => $lead->source,
            'assigned_to' => $lead->assigned_to,
            'customer.name' => $lead->customer?->name,
            'customer.phone' => $lead->customer?->phone,
            'product.name' => $lead->product?->name,
            'campaign_id' => $lead->campaign_id,
            'created_at' => $lead->created_at?->toDateTimeString(),
        ];
    }
}
