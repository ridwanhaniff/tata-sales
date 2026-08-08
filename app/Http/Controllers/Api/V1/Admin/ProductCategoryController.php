<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductCategoryRequest;
use App\Http\Requests\Product\UpdateProductCategoryRequest;
use App\Http\Resources\ProductCategoryResource;
use App\Models\ProductCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categories = ProductCategory::query()
            ->withCount('products')
            ->when($request->filled('parent_id'), fn ($q) => $q->where('parent_id', $request->string('parent_id')))
            ->orderBy('name')
            ->get();

        return ApiResponse::success(ProductCategoryResource::collection($categories));
    }

    public function store(StoreProductCategoryRequest $request): JsonResponse
    {
        $category = ProductCategory::create([
            ...$request->validated(),
            'tenant_id' => $request->attributes->get('tenant')?->id,
        ]);

        return ApiResponse::created(new ProductCategoryResource($category));
    }

    public function update(UpdateProductCategoryRequest $request, ProductCategory $category): JsonResponse
    {
        $category->update($request->validated());

        return ApiResponse::success(new ProductCategoryResource($category->fresh()));
    }

    public function destroy(ProductCategory $category): JsonResponse
    {
        $category->delete();

        return ApiResponse::noContent();
    }
}
