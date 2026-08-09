<?php

namespace App\Agents\Tools;

use App\Agents\Tools\Contracts\Tool;
use App\Services\Promotion\PromotionService;
use Illuminate\Support\Arr;

class GetPromotionTool implements Tool
{
    public function __construct(private readonly PromotionService $promotions) {}

    public function name(): string
    {
        return 'get_promotion';
    }

    public function description(): string
    {
        return 'Promo aktif untuk produk (atau semua bila tanpa product_id). Data promo disetujui tenant, window aktif sudah divalidasi server (§85).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => ['type' => 'string', 'description' => 'id produk (opsional) untuk meretriksi promo ke produk itu'],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $productId = Arr::get($arguments, 'product_id') ?: null;
        $promotions = $this->promotions->activeFor($productId);

        return [
            'found_count' => $promotions->count(),
            'results' => $promotions->map(fn ($promo) => [
                'promotion_id' => $promo->id,
                'name' => $promo->name,
                'discount_type' => $promo->discount_type,
                'discount_value' => (float) $promo->discount_value,
                'minimum_purchase' => $promo->minimum_purchase ? (int) $promo->minimum_purchase : null,
                'starts_at' => $promo->starts_at?->toIso8601String(),
                'ends_at' => $promo->ends_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
