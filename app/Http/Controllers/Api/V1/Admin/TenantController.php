<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreTenantRequest;
use App\Http\Requests\Tenant\UpdateTenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenants = Tenant::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', '%'.$request->string('search').'%'))
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 20) <= 100 ? $request->integer('per_page', 20) : 100);

        return ApiResponse::paginated($tenants, TenantResource::class);
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $tenant = Tenant::create($request->validated());

        return ApiResponse::created(new TenantResource($tenant));
    }

    public function show(Tenant $tenant): JsonResponse
    {
        return ApiResponse::success(new TenantResource($tenant));
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): JsonResponse
    {
        $tenant->update($request->validated());

        return ApiResponse::success(new TenantResource($tenant->fresh()));
    }

    public function destroy(Tenant $tenant): JsonResponse
    {
        $tenant->delete();

        return ApiResponse::noContent();
    }
}
