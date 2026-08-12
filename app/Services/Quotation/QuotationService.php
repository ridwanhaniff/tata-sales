<?php

namespace App\Services\Quotation;

use App\Models\Lead;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Crm\CrmService;
use App\Services\Lead\LeadService;
use App\Services\Notification\NotificationService;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\AuditLogger;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Quotation engine (§99, Sprint 12): penawaran harga per lead dengan
 * status draft → sent → viewed → accepted/rejected. Integrasi state
 * machine lead (06-lead-state-machine.md):
 *
 * - quotation dibuat   → lead QUALIFIED → PROPOSAL
 * - quotation dilihat  → PROPOSAL → NEGOTIATION
 * - quotation accepted → lead → WON
 * - quotation rejected → lead → LOST
 */
class QuotationService
{
    public function __construct(
        private readonly LeadService $leads,
        private readonly NotificationService $notifications,
        private readonly WhatsAppService $whatsapp,
        private readonly CrmService $crm,
    ) {}

    /**
     * Buat penawaran (draft) dari lead + daftar item.
     *
     * @param  array<int, array{product_id?: string, variant_id?: string, description?: string, quantity: int, unit_price?: float|string, discount?: float|string}>  $items
     */
    public function createFromLead(Lead $lead, array $items, ?string $notes = null, int $validDays = 7, ?User $actor = null): Quotation
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => ['Quotation minimal berisi satu item.'],
            ]);
        }

        $customer = $lead->customer;

        if (! $customer) {
            throw ValidationException::withMessages([
                'lead' => ['Lead belum memiliki customer.'],
            ]);
        }

        $quotation = Quotation::create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'created_by' => $actor?->id,
            'status' => 'draft',
            'valid_until' => now()->addDays(max(1, (int) $validDays)),
            'notes' => $notes,
        ]);

        $subtotal = 0;
        $discountTotal = 0;

        foreach ($items as $index => $item) {
            $resolved = $this->resolveLine($lead->tenant_id, $item, $index);

            QuotationItem::create([
                'tenant_id' => $lead->tenant_id,
                'quotation_id' => $quotation->id,
                ...$resolved,
            ]);

            $subtotal += $resolved['unit_price'] * $resolved['quantity'];
            $discountTotal += $resolved['discount'] * $resolved['quantity'];
        }

        $quotation->forceFill([
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'total' => $subtotal - $discountTotal,
        ])->save();

        try {
            if (in_array($lead->status, ['QUALIFIED', 'NEGOTIATION'], true)) {
                $this->leads->transition($lead, 'PROPOSAL', $actor);
            }
        } catch (ValidationException) {
            // pipeline kustom tenant mungkin tidak menyediakan jalur ini.
        }

        $this->leads->logEvent($lead, 'quotation_created', [
            'quotation_id' => $quotation->id,
            'by' => $actor?->id,
        ]);

        AuditLogger::log('quotation.created', 'quotation', $quotation->id, [], [
            'items' => count($items),
            'total' => $quotation->total,
        ]);

        return $quotation->fresh(['items', 'lead', 'customer']);
    }

    /**
     * Kirim quotation: status sent + token publik + notify + share via WhatsApp.
     */
    public function send(Quotation $quotation, ?User $actor = null): Quotation
    {
        if ($quotation->items()->count() === 0) {
            throw ValidationException::withMessages([
                'items' => ['Quotation kosong — tidak bisa dikirim.'],
            ]);
        }

        $quotation->forceFill([
            'status' => 'sent',
            'public_token' => $quotation->public_token ?? Str::random(32),
            'sent_at' => now(),
        ])->save();

        if ($quotation->lead && $quotation->lead->assigned_to) {
            $this->notifications->notify(
                $quotation->tenant_id,
                $quotation->lead->assigned_to,
                'quotation_sent',
                'Penawaran terkirim',
                'Penawaran untuk '.($quotation->customer?->name ?? '-').' terkirim.',
                [
                    'quotation_id' => $quotation->id,
                    'lead_id' => $quotation->lead_id,
                    'total' => (float) $quotation->total,
                ]
            );
        }

        AuditLogger::log('quotation.sent', 'quotation', $quotation->id, [], [
            'total' => (float) $quotation->total,
        ]);

        $tenant = Tenant::query()
            ->withoutGlobalScope('tenant')
            ->find($quotation->tenant_id);

        if ($tenant) {
            $this->crm->dispatch(
                $tenant,
                'quotation.sent',
                $this->crm->factory()->quotation('quotation.sent', $quotation)
            );
        }

        if ($quotation->lead && $quotation->lead->customer?->phone) {
            $this->whatsapp->send($quotation->lead, $this->shareMessage($quotation), quotation: $quotation);
        }

        return $quotation->fresh(['lead', 'customer', 'items']);
    }

    /**
     * Customer membuka link publik → tandai viewed (sekali) + transisi
     * lead PROPOSAL → NEGOTIATION.
     */
    public function openByToken(string $token): Quotation
    {
        $quotation = Quotation::query()
            ->withoutGlobalScope('tenant')
            ->where('public_token', $token)
            ->first();

        if (! $quotation) {
            throw ValidationException::withMessages([
                'token' => ['Link quotation tidak valid.'],
            ]);
        }

        if ($quotation->isExpired()) {
            $quotation->forceFill(['status' => 'expired'])->save();
        }

        if (in_array($quotation->status, ['sent', 'viewed', 'expired'], true) && ! $quotation->viewed_at) {
            $quotation->forceFill(['status' => 'viewed', 'viewed_at' => now()])->save();
        }

        if ($quotation->lead) {
            try {
                if ($quotation->lead->status === 'PROPOSAL') {
                    $this->leads->transition($quotation->lead, 'NEGOTIATION');
                }
            } catch (ValidationException) {
                // status sudah di luar jalur ini — biarkan.
            }

            $this->leads->logEvent($quotation->lead, 'quotation_viewed', [
                'quotation_id' => $quotation->id,
            ]);
        }

        return $quotation->fresh(['lead', 'customer', 'items']);
    }

    /**
     * Customer menyetujui → quotation accepted + lead WON.
     */
    public function acceptByToken(string $token, ?string $notes = null): Quotation
    {
        $quotation = $this->quotationByToken($token);

        if (! in_array($quotation->status, ['sent', 'viewed', 'expired'], true)) {
            throw ValidationException::withMessages([
                'token' => ['Quotation tidak dalam status yang bisa diterima.'],
            ]);
        }

        $quotation->forceFill([
            'status' => 'accepted',
            'responded_at' => now(),
            'notes' => $notes ?: $quotation->notes,
        ])->save();

        if ($quotation->lead) {
            $lead = $quotation->lead;

            $lead->forceFill(['estimated_value' => max((float) $lead->estimated_value, (float) $quotation->total)])->save();

            try {
                if (! in_array($lead->status, ['WON', 'LOST'], true)) {
                    // State machine (06): PROPOSAL → NEGOTIATION → WON.
                    if ($lead->status === 'PROPOSAL') {
                        $this->leads->transition($lead, 'NEGOTIATION');
                    }

                    $this->leads->transition($lead, 'WON');
                }
            } catch (ValidationException) {
                // pipeline kustom — biarkan, quotation tetap accepted.
            }

            $this->leads->logEvent($lead, 'quotation_accepted', [
                'quotation_id' => $quotation->id,
                'total' => (float) $quotation->total,
            ]);
        }

        $this->notifyResponded($quotation, true);

        $this->dispatchQuotationEvent($quotation, 'quotation.accepted');

        return $quotation->fresh(['lead', 'customer', 'items']);
    }

    /**
     * Customer menolak → rejected + lead LOST.
     */
    public function rejectByToken(string $token, ?string $reason = null): Quotation
    {
        $quotation = $this->quotationByToken($token);

        if (! in_array($quotation->status, ['sent', 'viewed', 'expired'], true)) {
            throw ValidationException::withMessages([
                'token' => ['Quotation tidak dalam status yang bisa ditolak.'],
            ]);
        }

        $quotation->forceFill([
            'status' => 'rejected',
            'responded_at' => now(),
            'notes' => $reason ?: $quotation->notes,
        ])->save();

        if ($quotation->lead) {
            $lead = $quotation->lead;

            try {
                if (! in_array($lead->status, ['WON', 'LOST'], true)) {
                    $this->leads->transition($lead, 'LOST');
                }
            } catch (ValidationException) {
                // pipeline kustom — biarkan.
            }

            if ($reason) {
                $this->leads->addNote($lead, 'Penolakan quotation: '.$reason);
            }

            $this->leads->logEvent($lead, 'quotation_rejected', [
                'quotation_id' => $quotation->id,
            ]);
        }

        $this->notifyResponded($quotation, false);

        $this->dispatchQuotationEvent($quotation, 'quotation.rejected');

        return $quotation->fresh(['lead', 'customer', 'items']);
    }

    /**
     * Tandai quotation draft/sent/viewed yang lewat valid_until jadi expired.
     *
     * @return int jumlah quotation yang di-expire
     */
    public function expireOverdue(?string $tenantId = null): int
    {
        $query = Quotation::query()
            ->whereIn('status', ['draft', 'sent', 'viewed'])
            ->where('valid_until', '<', now());

        if ($tenantId) {
            $query->withoutGlobalScope('tenant')->where('tenant_id', $tenantId);
        }

        $quotations = $query->get();

        $count = 0;

        foreach ($quotations as $quotation) {
            $quotation->forceFill(['status' => 'expired'])->save();

            $this->dispatchQuotationEvent($quotation, 'quotation.expired');

            $lead = $quotation->lead;

            if ($lead && in_array($lead->status, ['PROPOSAL', 'NEGOTIATION'], true)) {
                try {
                    $this->leads->transition($lead, 'LOST');
                } catch (ValidationException) {
                    // biarkan.
                }

                $this->leads->logEvent($lead, 'quotation_expired', [
                    'quotation_id' => $quotation->id,
                ]);
            }

            $count++;
        }

        return $count;
    }

    private function quotationByToken(string $token): Quotation
    {
        $quotation = Quotation::query()
            ->withoutGlobalScope('tenant')
            ->where('public_token', $token)
            ->first();

        if (! $quotation) {
            throw ValidationException::withMessages([
                'token' => ['Link quotation tidak valid.'],
            ]);
        }

        return $quotation;
    }

    /**
     * Resolve satu baris item: harga dari product/variant approval milik tenant,
     * boleh di-override unit_price dari input.
     *
     * @param  array{product_id?: string, variant_id?: string, description?: string, quantity: int, unit_price?: float|string, discount?: float|string}  $item
     * @return array{product_id: string|null, variant_id: string|null, description: string, quantity: int, unit_price: float, discount: float, line_total: float}
     */
    private function resolveLine(string $tenantId, array $item, int $index): array
    {
        $product = null;
        $variant = null;

        if (! empty($item['product_id'])) {
            $product = Product::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $item['product_id'])
                ->first();

            if (! $product) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => ['Produk tidak ditemukan / bukan milik tenant ini.'],
                ]);
            }
        }

        if (! empty($item['variant_id'])) {
            $variant = ProductVariant::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $item['variant_id'])
                ->first();

            if (! $variant) {
                throw ValidationException::withMessages([
                    "items.{$index}.variant_id" => ['Varian tidak ditemukan / bukan milik tenant ini.'],
                ]);
            }
        }

        $quantity = max(1, (int) ($item['quantity'] ?? 1));

        $unitPrice = isset($item['unit_price']) && $item['unit_price'] !== ''
            ? (float) $item['unit_price']
            : (float) ($variant?->price ?? $product?->base_price ?? 0);

        if ($unitPrice <= 0) {
            throw ValidationException::withMessages([
                "items.{$index}.unit_price" => ['Harga item harus lebih dari 0.'],
            ]);
        }

        $discount = (float) ($item['discount'] ?? 0);

        if ($discount < 0 || $discount >= $unitPrice) {
            throw ValidationException::withMessages([
                "items.{$index}.discount" => ['Diskon tidak valid (0 s.d. < harga satuan).'],
            ]);
        }

        $lineTotal = ($unitPrice - $discount) * $quantity;

        return [
            'product_id' => $product?->id,
            'variant_id' => $variant?->id,
            'description' => mb_substr((string) ($item['description'] ?? ($variant?->name ?? $product?->name ?? 'Item')), 0, 500),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount' => $discount,
            'line_total' => $lineTotal,
        ];
    }

    private function shareMessage(Quotation $quotation): string
    {
        $url = url('/quotes/'.$quotation->public_token);

        return 'Halo '.($quotation->customer?->name ?? 'Bapak/Ibu').', penawaran untuk Anda sudah kami siapkan: '.$url;
    }

    private function dispatchQuotationEvent(Quotation $quotation, string $event): void
    {
        $tenant = Tenant::query()
            ->withoutGlobalScope('tenant')
            ->find($quotation->tenant_id);

        if ($tenant) {
            $this->crm->dispatch(
                $tenant,
                $event,
                $this->crm->factory()->quotation($event, $quotation)
            );
        }
    }

    private function notifyResponded(Quotation $quotation, bool $accepted): void
    {
        if (! $quotation->lead?->assigned_to) {
            return;
        }

        $this->notifications->notify(
            $quotation->tenant_id,
            $quotation->lead->assigned_to,
            $accepted ? 'quotation_accepted' : 'quotation_rejected',
            $accepted ? 'Penawaran diterima' : 'Penawaran ditolak',
            ($accepted ? 'Customer menyetujui' : 'Customer menolak').' penawaran '.($quotation->customer?->name ?? '-').'.',
            [
                'quotation_id' => $quotation->id,
                'lead_id' => $quotation->lead_id,
            ]
        );
    }
}
