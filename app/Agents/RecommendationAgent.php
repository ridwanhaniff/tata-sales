<?php

namespace App\Agents;

use App\Agents\Tools\GetPromotionTool;
use App\Agents\Tools\SearchProductsTool;
use App\Agents\Values\LLMResponse;
use App\Services\Product\ProductService;
use App\Services\Promotion\PromotionService;

/**
 * Recommendation Agent (§5): rekomendasi produk sesuai konteks lead —
 * budget (estimated_value) dan produk yang diminati. Semua fakta hanya
 * dari tool (search_products + get_promotion); tidak pernah
 * merekomendasikan stok out_of_stock/hidden, tidak mengarang harga/promo.
 */
class RecommendationAgent extends Agent
{
    public function name(): string
    {
        return 'recommendation';
    }

    public function tools(): array
    {
        return [
            new SearchProductsTool(app(ProductService::class)),
            new GetPromotionTool(app(PromotionService::class)),
        ];
    }

    protected function systemPrompt(AgentContext $context): string
    {
        $lead = $context->meta['lead'] ?? null;
        $budget = (int) ($lead['estimated_value'] ?? 0);

        $hint = '';
        if ($lead) {
            $hint .= "\nKonteks lead tersedia: status {$lead['status']}";
            if ($budget > 0) {
                $hint .= ', budget customer Rp'.number_format($budget, 0, ',', '.').' — pakai sebagai budget_max saat memanggil search_products, jangan tawarkan produk di atasnya';
            }
            if (! empty($lead['product_id'])) {
                $hint .= ', lead tertarik pada produk tertentu (lihat daftar products di konteks)';
            }
        }

        return <<<PROMPT
Kamu adalah konsultan rekomendasi produk penjualan.
{$hint}

ATURAN WAJIB:
- Semua nama, harga, stok, dan promo HANYA dari hasil tool search_products dan get_promotion. JANGAN PERNAH mengarang.
- JANGAN merekomendasikan produk dengan stock_status 'out_of_stock' atau 'hidden'. Produk 'preorder' boleh disebut hanya dengan keterangan jelas.
- Kalau ada budget: tawarkan produk yang masih dalam budget; kalau tidak ada yang cocok, katakan jujur dan tawarkan bantuan tim kami.
- Promo boleh disebut kalau benar-benar muncul di hasil get_promotion untuk produk itu; jangan menambah potongan di luar data.
- Jawab dalam bahasa Indonesia, ringkas dan personal, tanpa emoji, tanpa markdown.
- Kalau hasil tool kosong: katakan jujur belum ada yang cocok dan tawarkan bantuan tim kami.
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
