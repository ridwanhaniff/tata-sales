<?php

namespace App\Agents\Tools;

use App\Agents\Tools\Contracts\Tool;
use App\Services\Knowledge\KnowledgeBaseService;
use Illuminate\Support\Arr;

/**
 * search_knowledge (§66): retrieval KB approved (FAQ/policy/script) —
 * purely deterministic, tenant-scoped. Output hanya isi artikel yang
 * memang ada di database tenant; AI tidak boleh mengarang isi kebijakan.
 */
class SearchKnowledgeTool implements Tool
{
    public function __construct(private readonly KnowledgeBaseService $knowledge) {}

    public function name(): string
    {
        return 'search_knowledge';
    }

    public function description(): string
    {
        return 'Cari artikel knowledge base resmi tenant (FAQ, kebijakan garansi/dp/pengiriman, skrip penjualan). Jawaban produk/kebijakan wajib bersumber dari hasil tool ini.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'question' => ['type' => 'string', 'description' => 'pertanyaan customer yang mau dicocokkan dengan isi knowledge base'],
            ],
            'required' => ['question'],
        ];
    }

    public function execute(array $arguments): array
    {
        $question = trim((string) Arr::get($arguments, 'question', ''));

        $articles = $this->knowledge->search($question, 5);

        $results = $articles->map(fn ($article) => [
            'article_id' => $article->id,
            'category' => $article->category,
            'title' => $article->title,
            'content' => $article->content,
        ])->values()->all();

        if ($results === []) {
            return [
                'found' => false,
                'found_count' => 0,
                'results' => [],
                'reason' => 'Tidak ada artikel knowledge base yang cocok.',
            ];
        }

        return [
            'found' => true,
            'found_count' => count($results),
            'results' => $results,
        ];
    }
}
