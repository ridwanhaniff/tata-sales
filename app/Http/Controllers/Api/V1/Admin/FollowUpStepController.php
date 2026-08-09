<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\FollowUp\StoreFollowUpStepRequest;
use App\Http\Resources\FollowUpStepResource;
use App\Models\FollowupStep;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowUpStepController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $steps = FollowupStep::query()
            ->when($request->filled('trigger_event'), fn ($q) => $q->where('trigger_event', $request->string('trigger_event')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(FollowUpStepResource::collection($steps));
    }

    public function store(StoreFollowUpStepRequest $request): JsonResponse
    {
        $step = FollowupStep::create([
            'tenant_id' => $request->attributes->get('tenant')?->id,
            'name' => $request->string('name')->toString(),
            'trigger_event' => $request->string('trigger_event')->toString(),
            'delay_minutes' => $request->integer('delay_minutes'),
            'message' => $request->input('message'),
            'condition' => $request->input('condition'),
            'action' => $request->input('action', 'create_followup'),
            'sort_order' => $request->integer('sort_order', 0),
            'status' => $request->input('status', 'active'),
        ]);

        return ApiResponse::created(new FollowUpStepResource($step));
    }

    public function update(StoreFollowUpStepRequest $request, FollowupStep $step): JsonResponse
    {
        $step->forceFill([
            'name' => $request->input('name', $step->name),
            'trigger_event' => $request->input('trigger_event', $step->trigger_event),
            'delay_minutes' => $request->input('delay_minutes', $step->delay_minutes),
            'message' => $request->input('message', $step->message),
            'condition' => $request->input('condition', $step->condition),
            'action' => $request->input('action', $step->action),
            'sort_order' => $request->input('sort_order', $step->sort_order),
            'status' => $request->input('status', $step->status),
        ])->save();

        return ApiResponse::success(new FollowUpStepResource($step->fresh()));
    }

    public function destroy(FollowupStep $step): JsonResponse
    {
        $step->delete();

        return ApiResponse::noContent();
    }
}
