<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Quotation;
use App\Services\Quotation\QuotationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class QuotationController extends Controller
{
    public function __construct(private readonly QuotationService $quotations) {}

    public function index(Request $request): JsonResponse
    {
        $queries = Quotation::query()
            ->with(['lead.customer', 'customer', 'items'])
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->string('lead_id')->toString(), fn ($q, $leadId) => $q->where('lead_id', $leadId))
            ->orderByDesc('created_at');

        return ApiResponse::paginated($queries);
    }

    public function show(Quotation $quotation): JsonResponse
    {
        return ApiResponse::success($quotation->load(['lead', 'customer', 'items', 'createdBy']));
    }

    /**
     * Buat quotation draft dari lead.
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'lead_id' => ['required', 'exists:leads,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'string'],
            'items.*.variant_id' => ['nullable', 'string'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0.01'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'valid_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $lead = Lead::query()->findOrFail($payload['lead_id']);

        $quotation = $this->quotations->createFromLead(
            $lead,
            $payload['items'],
            $payload['notes'] ?? null,
            (int) ($payload['valid_days'] ?? 7),
            $request->user()
        );

        return ApiResponse::created($quotation);
    }

    public function send(Quotation $quotation, Request $request): JsonResponse
    {
        if ($quotation->status !== 'draft') {
            throw ValidationException::withMessages([
                'quotation' => ['Hanya quotation draft yang bisa dikirim.'],
            ]);
        }

        $sent = $this->quotations->send($quotation, $request->user());

        return ApiResponse::success($sent);
    }

    public function destroy(Quotation $quotation): JsonResponse
    {
        if ($quotation->status !== 'draft') {
            throw ValidationException::withMessages([
                'quotation' => ['Hanya quotation draft yang bisa dihapus.'],
            ]);
        }

        $quotation->items()->delete();
        $quotation->delete();

        return ApiResponse::noContent();
    }
}
