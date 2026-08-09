<?php

namespace App\Agents;

use App\Agents\Contracts\LLMProvider;
use App\Agents\Support\ToolExecutor;
use App\Agents\Tools\GetProductTool;
use App\Agents\Tools\GetPromotionTool;
use App\Agents\Tools\RequestHumanTool;
use App\Agents\Tools\SearchKnowledgeTool;
use App\Agents\Tools\SearchProductsTool;
use App\Agents\Values\LLMResponse;
use App\Services\Knowledge\KnowledgeBaseService;
use App\Services\Product\ProductService;
use App\Services\Promotion\PromotionService;

/**
 * Product Agent — intent specification/comparison/availability.
 * Semua fakta (nama, harga, stok, promo, spesifikasi) hanya boleh
 * muncul dari tool yang membaca DB approved; system prompt secara
 * eksplisit melarang mengarang, dan arsitektur (loop tool + whitelist)
 * memastikan tidak ada jalur lain.
 */
class ProductAgent extends Agent
{
    public function __construct(LLMProvider $llm, ToolExecutor $executor)
    {
        parent::__construct($llm, $executor);
    }

    public function name(): string
    {
        return 'product';
    }

    public function tools(): array
    {
        return [
            new SearchProductsTool(app(ProductService::class)),
            new GetProductTool(app(ProductService::class)),
            new GetPromotionTool(app(PromotionService::class)),
            new SearchKnowledgeTool(app(KnowledgeBaseService::class)),
            new RequestHumanTool,
        ];
    }

    protected function systemPrompt(AgentContext $context): string
    {
        return <<<'PROMPT'
Kamu adalah asisten penjualan yang menjawab pertanyaan pelanggan tentang spesifikasi produk, ketersediaan, dan perbandingan.

ATURAN WAJIB:
- Semua data produk/harga/stok/promo HANYA dari hasil tool (search_products, get_product, get_promotion) yang sudah kamu panggil. JANGAN PERNAH mengarang harga, stok, spesifikasi, atau promo yang tidak muncul di hasil tool.
- Kebijakan/FAQ (garansi, dokumen, cara pengiriman, program) HANYA dari hasil tool search_knowledge. Jangan jawab dari ingatan umum.
- Kalau customer minta manusia, komplain, atau minta diskon di luar promo: panggil request_human, jangan nego sendiri.
- Jawab dalam bahasa Indonesia, natural dan ringkas, tanpa emoji, tanpa markdown.
- Kalau hasil tool kosong / tidak ditemukan: katakan jujur bahwa produk itu tidak tersedia di katalog kami, dan tawarkan menghubungkan ke tim kami — jangan menebak.
- Jangan sebutkan angka mana pun yang tidak berasal dari output tool.
PROMPT;
    }

    protected function finalize(AgentContext $context, array $messages, array $toolResults, LLMResponse $final): array
    {
        return [
            'reply' => $final->content,
            'tool_calls' => array_map(fn ($r) => [
                'tool' => $r->tool,
                'status' => $r->status,
            ], $toolResults),
        ];
    }
}
