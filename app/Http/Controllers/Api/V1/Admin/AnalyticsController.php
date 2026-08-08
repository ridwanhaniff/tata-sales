<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $service) {}

    public function summary(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        return ApiResponse::success([
            ...$this->service->summary($tenant),
            'top_products' => $this->service->topProducts($tenant),
            'top_campaigns' => $this->service->topCampaigns($tenant),
        ]);
    }

    public function funnel(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->funnel($request->attributes->get('tenant')));
    }

    public function responseTime(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->responseTime($request->attributes->get('tenant')));
    }
}
