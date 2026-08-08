<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use App\Services\Promotion\PromotionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    public function __construct(private readonly PromotionService $service) {}

    /**
     * Hanya promo yang lolos validasi window (§85) yang dikembalikan,
     * validasi di server, bukan di frontend.
     */
    public function active(Request $request): JsonResponse
    {
        $productId = $request->filled('product_id') ? $request->string('product_id')->toString() : null;

        $promotions = $this->service->activeFor($productId);

        return ApiResponse::success(PromotionResource::collection($promotions));
    }
}
