<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Calculator\CalculateRequest;
use App\Models\Calculator;
use App\Services\Calculator\CalculatorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CalculatorController extends Controller
{
    public function __construct(private readonly CalculatorService $service) {}

    public function calculate(CalculateRequest $request, Calculator $calculator): JsonResponse
    {
        abort_if($calculator->status !== 'active', 404);

        $result = $this->service->run(
            $calculator,
            $request->validated('inputs'),
            $request->validated('product_id'),
            $request->validated('lead_id'),
        );

        return ApiResponse::success([
            'session_id' => $result['session_id'],
            'outputs' => $result['outputs'],
        ]);
    }
}
