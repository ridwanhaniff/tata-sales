<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSalesTargetRequest;
use App\Http\Requests\Sales\UpdateSalesTargetRequest;
use App\Http\Resources\SalesTargetResource;
use App\Models\SalesTarget;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class SalesTargetController extends Controller
{
    public function index(): JsonResponse
    {
        $targets = SalesTarget::query()
            ->with(['user', 'team'])
            ->orderByDesc('period')
            ->get();

        return ApiResponse::success(SalesTargetResource::collection($targets));
    }

    public function store(StoreSalesTargetRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tenantId = $request->attributes->get('tenant')?->id;

        $this->guardDuplicate($tenantId, $data);

        $target = SalesTarget::create([...$data, 'tenant_id' => $tenantId]);

        return ApiResponse::created(new SalesTargetResource($target->load(['user', 'team'])));
    }

    public function update(UpdateSalesTargetRequest $request, SalesTarget $target): JsonResponse
    {
        $data = $request->validated();

        $this->guardDuplicate($target->tenant_id, $data, $target);

        $target->forceFill($data)->save();

        return ApiResponse::success(new SalesTargetResource($target->load(['user', 'team'])));
    }

    public function destroy(SalesTarget $target): JsonResponse
    {
        $target->delete();

        return ApiResponse::noContent();
    }

    /**
     * Satu target per (user | team, period) untuk mencegah janji ganda.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardDuplicate(?string $tenantId, array $data, ?SalesTarget $except = null): void
    {
        $hasUser = isset($data['user_id']) && $data['user_id'];
        $hasTeam = isset($data['sales_team_id']) && $data['sales_team_id'];

        if ($hasUser === $hasTeam) {
            throw ValidationException::withMessages([
                'sales_team_id' => ['Isi salah satu: user_id ATAU sales_team_id, tidak keduanya.'],
            ]);
        }

        $query = SalesTarget::query()
            ->where('tenant_id', $tenantId)
            ->where('period', $data['period'])
            ->when($hasUser, fn ($q) => $q->where('user_id', $data['user_id']))
            ->when($hasTeam, fn ($q) => $q->where('sales_team_id', $data['sales_team_id']));

        if ($except) {
            $query->where('id', '!=', $except->id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'period' => 'Target untuk kombinasi ini sudah ada.',
            ]);
        }
    }
}
