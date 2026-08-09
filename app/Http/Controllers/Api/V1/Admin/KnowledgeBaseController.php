<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBaseArticle;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeBaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $articles = KnowledgeBaseArticle::query()
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($q) use ($request) {
                    $q->where('title', 'ilike', '%'.$request->string('search').'%')
                        ->orWhere('content', 'ilike', '%'.$request->string('search').'%');
                });
            })
            ->orderByDesc('updated_at')
            ->paginate(min($request->integer('per_page', 20), 100));

        return ApiResponse::paginated($articles);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'in:faq,policy,script'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:20000'],
            'keywords' => ['sometimes', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ]);

        $article = KnowledgeBaseArticle::create([
            ...$data,
            'tenant_id' => $request->attributes->get('tenant')?->id,
            'status' => $data['status'] ?? 'active',
        ]);

        return ApiResponse::created($this->payload($article));
    }

    public function show(KnowledgeBaseArticle $article): JsonResponse
    {
        return ApiResponse::success($this->payload($article));
    }

    public function update(Request $request, KnowledgeBaseArticle $article): JsonResponse
    {
        $data = $request->validate([
            'category' => ['sometimes', 'string', 'in:faq,policy,script'],
            'title' => ['sometimes', 'string', 'max:255'],
            'content' => ['sometimes', 'string', 'max:20000'],
            'keywords' => ['sometimes', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ]);

        $article->forceFill($data)->save();

        return ApiResponse::success($this->payload($article));
    }

    public function destroy(KnowledgeBaseArticle $article): JsonResponse
    {
        $article->delete();

        return ApiResponse::noContent();
    }

    private function payload(KnowledgeBaseArticle $article): array
    {
        return [
            'id' => $article->id,
            'category' => $article->category,
            'title' => $article->title,
            'content' => $article->content,
            'keywords' => $article->keywords,
            'status' => $article->status,
            'created_at' => $article->created_at?->toIso8601String(),
            'updated_at' => $article->updated_at?->toIso8601String(),
        ];
    }
}
