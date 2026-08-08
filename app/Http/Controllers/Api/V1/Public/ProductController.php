<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->with(['category', 'images'])
            ->when($request->filled('category'), function ($q) use ($request) {
                $q->whereHas('category', fn ($c) => $c->where('slug', $request->string('category')));
            })
            ->when($request->filled('featured'), fn ($q) => $q->where('featured', (bool) $request->boolean('featured')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($q) use ($request) {
                    $q->where('name', 'ilike', '%'.$request->string('search').'%')
                        ->orWhere('short_description', 'ilike', '%'.$request->string('search').'%');
                });
            })
            ->when($request->filled('sort'), function ($q) use ($request) {
                match ($request->string('sort')) {
                    'price_asc' => $q->orderBy('base_price', 'asc'),
                    'price_desc' => $q->orderBy('base_price', 'desc'),
                    'name' => $q->orderBy('name', 'asc'),
                    default => $q->orderByDesc('created_at'),
                };
            }, fn ($q) => $q->orderByDesc('created_at'))
            ->paginate(min($request->integer('per_page', 20), 100));

        return ApiResponse::paginated($products, ProductResource::class);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->with(['category', 'images', 'variants', 'attributes'])
            ->first();

        abort_if(! $product, 404);

        return ApiResponse::success(new ProductResource($product));
    }
}
