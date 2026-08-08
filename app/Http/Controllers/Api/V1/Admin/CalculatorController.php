<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Calculator\StoreCalculatorRequest;
use App\Http\Requests\Calculator\UpdateCalculatorRequest;
use App\Http\Resources\CalculatorResource;
use App\Models\Calculator;
use App\Services\Calculator\CalculatorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalculatorController extends Controller
{
    public function __construct(private readonly CalculatorService $service) {}

    public function index(Request $request): JsonResponse
    {
        $calculators = Calculator::query()
            ->with(['inputs', 'rules', 'outputs'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($q) use ($request) {
                    $q->where('name', 'ilike', '%'.$request->string('search').'%');
                });
            })
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 20), 100));

        return ApiResponse::paginated($calculators, CalculatorResource::class);
    }

    public function store(StoreCalculatorRequest $request): JsonResponse
    {
        $calculator = $this->service->create($request->validated(), $request->attributes->get('tenant')?->id);

        return ApiResponse::created(new CalculatorResource($calculator->load(['inputs', 'rules', 'outputs'])));
    }

    public function show(Calculator $calculator): JsonResponse
    {
        return ApiResponse::success(new CalculatorResource($calculator->load(['inputs', 'rules', 'outputs'])));
    }

    public function update(UpdateCalculatorRequest $request, Calculator $calculator): JsonResponse
    {
        $calculator = $this->service->update($calculator, $request->validated());

        return ApiResponse::success(new CalculatorResource($calculator->load(['inputs', 'rules', 'outputs'])));
    }

    public function destroy(Calculator $calculator): JsonResponse
    {
        $calculator->delete();

        return ApiResponse::noContent();
    }
}
