<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lead\StoreLeadRequest;
use App\Services\Lead\LeadService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class LeadController extends Controller
{
    public function __construct(private readonly LeadService $service) {}

    /**
     * Full pipeline submit lead (§112): validate → normalize phone →
     * find/create customer → create lead → score → assign → log → notify.
     */
    public function store(StoreLeadRequest $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $result = $this->service->createFromForm($request->validated(), $tenant, $request);

        $lead = $result['lead'];

        $payload = [
            'lead_id' => $lead->id,
            'status' => $lead->status,
            'temperature' => $lead->temperature,
            'score' => $lead->score,
            'assigned_to' => $result['assigned_to'],
        ];

        return ApiResponse::success($payload, status: $result['created'] ? 201 : 200);
    }
}
