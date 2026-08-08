<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\StorePipelineStageRequest;
use App\Http\Requests\Pipeline\UpdatePipelineStageRequest;
use App\Http\Resources\PipelineStageResource;
use App\Models\PipelineStage;
use App\Services\Pipeline\PipelineStageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PipelineStageController extends Controller
{
    public function __construct(private readonly PipelineStageService $service) {}

    public function index(): JsonResponse
    {
        $stages = PipelineStage::query()
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get();

        return ApiResponse::success(PipelineStageResource::collection($stages));
    }

    public function store(StorePipelineStageRequest $request): JsonResponse
    {
        $stage = $this->service->create($request->validated(), $request->attributes->get('tenant')?->id);

        return ApiResponse::created(new PipelineStageResource($stage));
    }

    public function update(UpdatePipelineStageRequest $request, PipelineStage $stage): JsonResponse
    {
        $stage = $this->service->update($stage, $request->validated());

        return ApiResponse::success(new PipelineStageResource($stage));
    }

    public function destroy(PipelineStage $stage): JsonResponse
    {
        $this->service->delete($stage);

        return ApiResponse::noContent();
    }
}
