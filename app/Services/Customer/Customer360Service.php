<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Note;
use Illuminate\Support\Collection;

class Customer360Service
{
    /**
     * Customer 360 view (§98): profil + semua lead + journey timeline
     * (lead events, notes, voucher usages) urut terbaru.
     *
     * @return array{customer: Customer, leads: Collection, timeline: list<array<string, mixed>>}
     */
    public function view(Customer $customer): array
    {
        $leads = Lead::query()
            ->with(['product', 'campaign', 'assignedUser'])
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $timeline = [];

        foreach (LeadEvent::query()
            ->whereIn('lead_id', $leads->pluck('id'))
            ->with('lead')
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get() as $event) {
            $timeline[] = [
                'at' => $event->occurred_at?->toIso8601String(),
                'type' => 'lead_event',
                'label' => $this->eventLabel($event->event_type),
                'event_type' => $event->event_type,
                'lead_id' => $event->lead_id,
                'lead_status' => $event->lead?->status,
                'payload' => $event->event_data,
            ];
        }

        foreach (Note::query()
            ->where('customer_id', $customer->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get() as $note) {
            $timeline[] = [
                'at' => $note->created_at?->toIso8601String(),
                'type' => 'note',
                'label' => 'Catatan sales',
                'content' => $note->content,
                'lead_id' => $note->lead_id,
                'actor' => $note->user?->name,
            ];
        }

        foreach ($customer->calculatorSessions()->orderByDesc('created_at')->limit(50)->get() as $session) {
            $timeline[] = [
                'at' => $session->created_at?->toIso8601String(),
                'type' => 'calculator',
                'label' => 'Menggunakan kalkulator',
                'lead_id' => $session->lead_id,
                'payload' => [
                    'input' => $session->input_data,
                    'output' => $session->output_data,
                ],
            ];
        }

        foreach ($customer->voucherUsages()->with('voucher')->orderByDesc('used_at')->limit(50)->get() as $usage) {
            $timeline[] = [
                'at' => $usage->used_at?->toIso8601String(),
                'type' => 'voucher',
                'label' => 'Menggunakan voucher '.($usage->voucher?->code ?? ''),
                'lead_id' => $usage->lead_id,
                'payload' => [
                    'voucher_id' => $usage->voucher_id,
                    'code' => $usage->voucher?->code,
                    'discount_type' => $usage->voucher?->discount_type,
                    'discount_value' => $usage->voucher?->discount_value,
                ],
            ];
        }

        usort($timeline, fn ($a, $b) => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));

        return [
            'customer' => $customer,
            'leads' => $leads,
            'timeline' => $timeline,
        ];
    }

    private function eventLabel(string $eventType): string
    {
        return [
            'lead_created' => 'Lead dibuat',
            'calculator_completed' => 'Kalkulator selesai',
            'sales_assigned' => 'Lead di-assign ke sales',
            'contacted' => 'Lead dihubungi',
            'qualified' => 'Lead terqualifikasi',
            'won' => 'Deal menang',
            'lost' => 'Deal batal',
            'note_added' => 'Catatan ditambahkan',
            'nurture' => 'Pindah ke nurture',
            'proposal' => 'Proposal dibuat',
            'negotiation' => 'Masuk negosiasi',
        ][$eventType] ?? 'Aktivitas lead';
    }
}
