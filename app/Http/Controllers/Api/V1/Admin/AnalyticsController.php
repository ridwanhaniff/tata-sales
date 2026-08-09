<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Analytics\AnalyticsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $service) {}

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('tenant') ?? app('currentTenant');
    }

    public function summary(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);

        return ApiResponse::success([
            ...$this->service->summary($tenant),
            'top_products' => $this->service->topProducts($tenant),
            'top_campaigns' => $this->service->topCampaigns($tenant),
        ]);
    }

    public function funnel(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->funnel($this->tenant($request)));
    }

    public function responseTime(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->responseTime($this->tenant($request)));
    }

    public function winRate(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->winRate($this->tenant($request)));
    }

    public function pipeline(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->pipeline($this->tenant($request)));
    }

    public function campaignRoi(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->campaignRoi($this->tenant($request)));
    }
}
