<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\Quotation\QuotationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Link publik quotation (§99 Sprint 12): customer membuka link dari
 * WhatsApp → status viewed; bisa langsung terima/tolak dari link.
 */
class QuotationTrackingController extends Controller
{
    public function __construct(private readonly QuotationService $quotations) {}

    public function show(string $token): JsonResponse
    {
        $quotation = $this->quotations->openByToken($token);

        return ApiResponse::success([
            'id' => $quotation->id,
            'status' => $quotation->status,
            'valid_until' => $quotation->valid_until?->toIso8601String(),
            'viewed_at' => $quotation->viewed_at?->toIso8601String(),
            'customer' => $quotation->customer?->name,
            'subtotal' => (float) $quotation->subtotal,
            'discount_total' => (float) $quotation->discount_total,
            'total' => (float) $quotation->total,
            'notes' => $quotation->notes,
            'items' => $quotation->items->map(fn ($item) => [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'discount' => (float) $item->discount,
                'line_total' => (float) $item->line_total,
            ])->values(),
        ]);
    }

    public function respond(Request $request, string $token): JsonResponse
    {
        $decision = $request->validate([
            'decision' => ['required', 'in:accept,reject'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ])['decision'];

        $quotation = $decision === 'accept'
            ? $this->quotations->acceptByToken($token, $request->input('reason'))
            : $this->quotations->rejectByToken($token, $request->input('reason'));

        return ApiResponse::success([
            'id' => $quotation->id,
            'status' => $quotation->status,
            'responded_at' => $quotation->responded_at?->toIso8601String(),
        ]);
    }
}
