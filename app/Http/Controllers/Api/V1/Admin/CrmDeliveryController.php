<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CrmDeliveryResource;
use App\Models\CrmDelivery;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Delivery log webhook CRM keluar (§78, Sprint 13) — riwayat tiap event
 * yang dikirim ke konektor; filter status/event/provinsi untuk audit.
 */
class CrmDeliveryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $deliveries = CrmDelivery::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->string('event')))
            ->when($request->filled('provider'), fn ($q) => $q->where('provider', $request->string('provider')))
            ->latest('created_at')
            ->paginate($request->integer('per_page', 20) <= 100 ? $request->integer('per_page', 20) : 100);

        return ApiResponse::paginated($deliveries, CrmDeliveryResource::class);
    }

    public function show(CrmDelivery $delivery): JsonResponse
    {
        return ApiResponse::success(new CrmDeliveryResource($delivery));
    }
}
