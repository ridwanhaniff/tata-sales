<?php

namespace App\Services\Crm;

use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Quotation;

/**
 * Schema baku event CRM keluar (§78, Sprint 13) — semua call site wajib
 * lewat sini supaya payload ke konektor (http/hubspot) konsisten dan bisa
 * dipetakan CRM eksternal. Event: lead.created, lead.updated, deal.won,
 * deal.lost, quotation.sent, quotation.viewed, quotation.accepted,
 * quotation.rejected, quotation.expired.
 */
class CrmEventFactory
{
    /**
     * @param  array<string, mixed>  $extra  field tambahan di top-level
     *                                       (mis. from/to untuk lead.updated)
     */
    public function lead(string $event, Lead $lead, array $extra = []): array
    {
        $customer = $lead->customer;
        $product = $lead->product;
        $campaign = $lead->campaign;
        $sales = $lead->assignedUser;

        return [
            ...$extra,
            'lead_id' => $lead->id,
            'status' => $lead->status,
            'pipeline_stage' => [
                'key' => $lead->status,
                'name' => $this->stageName($lead),
            ],
            'temperature' => $lead->temperature,
            'score' => (int) $lead->score,
            'source' => $lead->source,
            'estimated_value' => $lead->estimated_value !== null ? (float) $lead->estimated_value : null,
            'customer_id' => $lead->customer_id,
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
            ] : null,
            'product_id' => $lead->product_id,
            'product' => $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku ?? null,
                'base_price' => $product->base_price !== null ? (float) $product->base_price : null,
            ] : null,
            'campaign_id' => $lead->campaign_id,
            'campaign' => $campaign ? [
                'id' => $campaign->id,
                'name' => $campaign->name,
            ] : null,
            'assigned_sales' => $sales ? [
                'id' => $sales->id,
                'name' => $sales->name,
            ] : null,
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    public function quotation(string $event, Quotation $quotation): array
    {
        $customer = $quotation->customer;

        $items = [];
        foreach ($quotation->items ?? [] as $item) {
            $items[] = [
                'product_id' => $item->product_id ?? null,
                'name' => $item->description ?? $item->name ?? null,
                'quantity' => (int) ($item->quantity ?? 1),
                'unit_price' => isset($item->unit_price) ? (float) $item->unit_price : null,
                'subtotal' => isset($item->line_total)
                    ? (float) $item->line_total
                    : (float) (($item->unit_price ?? 0) * ($item->quantity ?? 1)),
            ];
        }

        return [
            'quotation_id' => $quotation->id,
            'number' => $quotation->number ?? $quotation->id,
            'status' => $quotation->status,
            'lead_id' => $quotation->lead_id,
            'customer_id' => $quotation->customer_id,
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
            ] : null,
            'items' => $items,
            'subtotal' => (float) $quotation->subtotal,
            'discount_total' => (float) $quotation->discount_total,
            'total' => (float) $quotation->total,
            'currency' => $quotation->currency ?? 'IDR',
            'valid_until' => $quotation->valid_until?->toIso8601String(),
            'sent_at' => $quotation->sent_at?->toIso8601String(),
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    private function stageName(Lead $lead): string
    {
        $stage = PipelineStage::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $lead->tenant_id)
            ->where('key', $lead->status)
            ->first();

        return $stage?->label ?? $lead->status;
    }
}
