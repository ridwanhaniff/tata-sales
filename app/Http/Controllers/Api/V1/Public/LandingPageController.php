<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\LandingPageResource;
use App\Models\LandingPage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class LandingPageController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $page = LandingPage::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->with(['sections' => fn ($q) => $q->where('status', 'active')->orderBy('sort_order')])
            ->first();

        abort_if(! $page, 404);

        return ApiResponse::success(new LandingPageResource($page));
    }
}
