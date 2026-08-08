<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promotion\StorePromotionRequest;
use App\Http\Requests\Promotion\UpdatePromotionRequest;
use App\Http\Resources\PromotionResource;
use App\Models\Promotion;
use App\Services\Promotion\PromotionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    public function __construct(private readonly PromotionService $service) {}

    public function index(Request $request): JsonResponse
    {
        $promotions = Promotion::query()
            ->with(['rules', 'products'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($q) use ($request) {
                    $q->where('name', 'ilike', '%'.$request->string('search').'%')
                        ->orWhere('description', 'ilike', '%'.$request->string('search').'%');
                });
            })
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 20), 100));

        return ApiResponse::paginated($promotions, PromotionResource::class);
    }

    public function store(StorePromotionRequest $request): JsonResponse
    {
        $promotion = $this->service->create($request->validated(), $request->attributes->get('tenant')?->id);

        return ApiResponse::created(new PromotionResource($promotion->load(['rules', 'products'])));
    }

    public function show(Promotion $promotion): JsonResponse
    {
        return ApiResponse::success(new PromotionResource($promotion->load(['rules', 'products'])));
    }

    public function update(UpdatePromotionRequest $request, Promotion $promotion): JsonResponse
    {
        $promotion = $this->service->update($promotion, $request->validated());

        return ApiResponse::success(new PromotionResource($promotion->load(['rules', 'products'])));
    }

    public function destroy(Promotion $promotion): JsonResponse
    {
        $promotion->delete();

        return ApiResponse::noContent();
    }
}
