<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductImageRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Product\ProductImageService;
use App\Services\Product\ProductService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly ProductImageService $imageService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->with(['category', 'images'])
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->string('category_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('featured'), fn ($q) => $q->where('featured', (bool) $request->boolean('featured')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($q) use ($request) {
                    $q->where('name', 'ilike', '%'.$request->string('search').'%')
                        ->orWhere('slug', 'ilike', '%'.$request->string('search').'%');
                });
            })
            ->orderBy($request->string('sort', 'created_at'), 'desc')
            ->paginate(min($request->integer('per_page', 20), 100));

        return ApiResponse::paginated($products, ProductResource::class);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->create($request->validated(), $request->attributes->get('tenant')?->id);

        return ApiResponse::created(new ProductResource($product->load(['category', 'images', 'variants', 'attributes'])));
    }

    public function show(Product $product): JsonResponse
    {
        return ApiResponse::success(new ProductResource($product->load(['category', 'images', 'variants', 'attributes'])));
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product = $this->productService->update($product, $request->validated());

        return ApiResponse::success(new ProductResource($product->load(['category', 'images', 'variants', 'attributes'])));
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return ApiResponse::noContent();
    }

    public function publish(Product $product): JsonResponse
    {
        return ApiResponse::success(new ProductResource($this->productService->publish($product)->load(['category', 'images'])));
    }

    public function unpublish(Product $product): JsonResponse
    {
        return ApiResponse::success(new ProductResource($this->productService->unpublish($product)->load(['category', 'images'])));
    }

    public function uploadImages(StoreProductImageRequest $request, Product $product): JsonResponse
    {
        $images = collect($request->file('images'))->map(
            fn ($file) => $this->imageService->store($product, $file, $request->input('alt_text'))
        );

        return ApiResponse::created([
            'images' => $images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url,
                'alt_text' => $image->alt_text,
                'sort_order' => $image->sort_order,
            ]),
        ]);
    }

    public function deleteImage(Product $product, ProductImage $image): JsonResponse
    {
        abort_if($image->product_id !== $product->id, 404);

        $image->delete();

        return ApiResponse::noContent();
    }
}
