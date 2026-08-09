<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeBaseArticle;
use Illuminate\Support\Collection;

/**
 * Knowledge Base v1 (§66) — retrieval deterministik sederhana:
 * matching keyword → title → content, tanpa AI dan tanpa vector/RAG.
 * Agent hanya boleh mengakses lewat tool search_knowledge.
 */
class KnowledgeBaseService
{
    /**
     * Cari artikel aktif yang cocok dengan pertanyaan.
     *
     * @return Collection<int, KnowledgeBaseArticle> diurutkan skor terbaik dulu
     */
    public function search(?string $query, int $limit = 10): Collection
    {
        $query = trim((string) $query);
        $base = KnowledgeBaseArticle::query()->active();

        if ($query === '') {
            return $base
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get();
        }

        $terms = $this->terms($query, 8);

        if ($terms === []) {
            return collect();
        }

        return $base->get()
            ->map(function (KnowledgeBaseArticle $article) use ($terms) {
                return [
                    'score' => $this->score($article, $terms),
                    'article' => $article,
                ];
            })
            ->filter(fn (array $row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $row) => $row['article'])
            ->values();
    }

    /**
     * @return list<string>
     */
    private function terms(string $input, int $maxTokens): array
    {
        $tokens = preg_split('/[\s\p{P}]+/u', mb_strtolower($input), -1, PREG_SPLIT_NO_EMPTY) ?? [];

        return array_slice(array_values($tokens), 0, $maxTokens);
    }

    /**
     * @param  list<string>  $terms
     */
    private function score(KnowledgeBaseArticle $article, array $terms): int
    {
        $keywords = array_map('mb_strtolower', (array) $article->keywords);
        $title = mb_strtolower((string) $article->title);
        $content = mb_strtolower((string) $article->content);

        $score = 0;

        foreach ($terms as $term) {
            if (in_array($term, $keywords, true)) {
                $score += 10;
            }

            if (str_contains($title, $term)) {
                $score += 5;
            }

            if (str_contains($content, $term)) {
                $score += 1;
            }
        }

        return $score;
    }
}
