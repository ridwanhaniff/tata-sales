<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tracking\TrackEventRequest;
use App\Models\CampaignEvent;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class EventController extends Controller
{
    public function track(TrackEventRequest $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $event = CampaignEvent::create([
            'tenant_id' => $tenant?->id,
            'campaign_id' => $request->input('campaign_id'),
            'visitor_id' => $request->input('visitor_id', Str::uuid()->toString()),
            'event_type' => $request->validated('event_type'),
            'event_data' => $request->input('event_data', []),
            'occurred_at' => now(),
        ]);

        return ApiResponse::created([
            'event_id' => $event->id,
            'visitor_id' => $event->visitor_id,
        ]);
    }
}
