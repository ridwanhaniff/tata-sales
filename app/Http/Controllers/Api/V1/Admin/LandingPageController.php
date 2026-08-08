<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LandingPage\StoreLandingPageRequest;
use App\Http\Requests\LandingPage\StorePageSectionRequest;
use App\Http\Requests\LandingPage\UpdateLandingPageRequest;
use App\Http\Requests\LandingPage\UpdatePageSectionRequest;
use App\Http\Resources\LandingPageResource;
use App\Http\Resources\PageSectionResource;
use App\Models\LandingPage;
use App\Models\PageSection;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LandingPageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pages = LandingPage::query()
            ->with('sections')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($q) use ($request) {
                    $q->where('title', 'ilike', '%'.$request->string('search').'%')
                        ->orWhere('slug', 'ilike', '%'.$request->string('search').'%');
                });
            })
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 20), 100));

        return ApiResponse::paginated($pages, LandingPageResource::class);
    }

    public function store(StoreLandingPageRequest $request): JsonResponse
    {
        $data = $request->validated();

        $page = LandingPage::create([
            ...$data,
            'tenant_id' => $request->attributes->get('tenant')?->id,
            'status' => $data['status'] ?? 'draft',
        ]);

        return ApiResponse::created(new LandingPageResource($page));
    }

    public function show(LandingPage $landingPage): JsonResponse
    {
        return ApiResponse::success(new LandingPageResource($landingPage->load('sections')));
    }

    public function update(UpdateLandingPageRequest $request, LandingPage $landingPage): JsonResponse
    {
        $landingPage->update($request->validated());

        return ApiResponse::success(new LandingPageResource($landingPage->fresh()->load('sections')));
    }

    public function destroy(LandingPage $landingPage): JsonResponse
    {
        $landingPage->delete();

        return ApiResponse::noContent();
    }

    public function publish(LandingPage $landingPage): JsonResponse
    {
        $landingPage->update([
            'status' => 'published',
            'published_at' => $landingPage->published_at ?? now(),
        ]);

        return ApiResponse::success(new LandingPageResource($landingPage->fresh()->load('sections')));
    }

    public function unpublish(LandingPage $landingPage): JsonResponse
    {
        $landingPage->update([
            'status' => 'draft',
            'published_at' => null,
        ]);

        return ApiResponse::success(new LandingPageResource($landingPage->fresh()->load('sections')));
    }

    public function sections(LandingPage $landingPage): JsonResponse
    {
        return ApiResponse::success(PageSectionResource::collection($landingPage->sections()->orderBy('sort_order')->get()));
    }

    public function storeSection(StorePageSectionRequest $request, LandingPage $landingPage): JsonResponse
    {
        $sortOrder = $request->input('sort_order')
            ?? (int) $landingPage->sections()->max('sort_order') + 1;

        $section = PageSection::create([
            'tenant_id' => $landingPage->tenant_id,
            'landing_page_id' => $landingPage->id,
            'block_type' => $request->validated('block_type'),
            'sort_order' => $sortOrder,
            'config' => $request->validated('config'),
            'status' => $request->input('status', 'active'),
        ]);

        return ApiResponse::created(new PageSectionResource($section));
    }

    public function updateSection(UpdatePageSectionRequest $request, LandingPage $landingPage, PageSection $section): JsonResponse
    {
        abort_if($section->landing_page_id !== $landingPage->id, 404);

        $section->update($request->validated());

        return ApiResponse::success(new PageSectionResource($section->fresh()));
    }

    public function destroySection(LandingPage $landingPage, PageSection $section): JsonResponse
    {
        abort_if($section->landing_page_id !== $landingPage->id, 404);

        $section->delete();

        return ApiResponse::noContent();
    }

    public function reorder(Request $request, LandingPage $landingPage): JsonResponse
    {
        $request->validate([
            'sections' => ['required', 'array'],
            'sections.*.id' => ['required', 'uuid'],
        ]);

        DB::transaction(function () use ($request, $landingPage) {
            foreach ($request->input('sections') as $i => $item) {
                PageSection::query()
                    ->where('landing_page_id', $landingPage->id)
                    ->where('id', $item['id'])
                    ->update(['sort_order' => $i]);
            }
        });

        return ApiResponse::success(PageSectionResource::collection($landingPage->sections()->orderBy('sort_order')->get()));
    }
}
