<?php

namespace App\Services\Promotion;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionProduct;
use App\Models\PromotionRule;
use App\Support\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PromotionService
{
    /**
     * Promo aktif yang lolos validasi window (§85):
     * status = active DAN now() antara starts_at dan ends_at.
     * Kalau $productId diberikan, hanya promo yang berlaku untuk produk itu
     * (via promotion_products atau rule product/category).
     */
    public function activeFor(?string $productId = null): Collection
    {
        $query = Promotion::query()
            ->activeWindow()
            ->with(['rules', 'products']);

        if ($productId !== null) {
            $product = Product::query()->find($productId);

            if (! $product) {
                return collect();
            }

            $query->where(function ($q) use ($product) {
                $q->where(function ($q) {
                    $q->whereDoesntHave('products')
                        ->whereDoesntHave('rules', function ($q) {
                            $q->whereIn('rule_type', ['product', 'category']);
                        });
                })
                    ->orWhereHas('products', fn ($q) => $q->where('products.id', $product->id))
                    ->orWhereHas('rules', function ($q) use ($product) {
                        $q->where('rule_type', 'product')
                            ->whereJsonContains('value->product_id', $product->id);
                    })
                    ->orWhereHas('rules', function ($q) use ($product) {
                        $q->where('rule_type', 'category')
                            ->whereJsonContains('value->category_id', $product->category_id);
                    });
            });
        }

        return $query->orderBy('starts_at')->get();
    }

    public function create(array $data, ?string $tenantId = null): Promotion
    {
        return DB::transaction(function () use ($data, $tenantId) {
            $promotion = Promotion::create([
                ...Arr::only($data, [
                    'name', 'description', 'discount_type', 'discount_value',
                    'minimum_purchase', 'usage_limit', 'starts_at', 'ends_at',
                ]),
                'tenant_id' => $tenantId,
                'status' => $data['status'] ?? 'draft',
                'usage_count' => 0,
            ]);

            $this->syncProducts($promotion, $data['product_ids'] ?? []);
            $this->syncRules($promotion, $data['rules'] ?? []);

            AuditLogger::log('promo.created', 'promotion', $promotion->id, [], Arr::only($data, [
                'name', 'discount_type', 'discount_value', 'starts_at', 'ends_at', 'status',
            ]));

            return $promotion->fresh();
        });
    }

    public function update(Promotion $promotion, array $data): Promotion
    {
        $before = Arr::only($promotion->getAttributes(), [
            'name', 'discount_type', 'discount_value', 'minimum_purchase',
            'usage_limit', 'starts_at', 'ends_at', 'status',
        ]);

        return DB::transaction(function () use ($promotion, $data, $before) {
            $promotion->update(Arr::only($data, [
                'name', 'description', 'discount_type', 'discount_value',
                'minimum_purchase', 'usage_limit', 'starts_at', 'ends_at', 'status',
            ]));

            if (array_key_exists('product_ids', $data)) {
                $this->syncProducts($promotion, $data['product_ids']);
            }

            if (array_key_exists('rules', $data)) {
                $this->syncRules($promotion, $data['rules']);
            }

            AuditLogger::log('promo.updated', 'promotion', $promotion->id, $before, Arr::only(
                $promotion->fresh()->getAttributes(),
                ['name', 'discount_type', 'discount_value', 'minimum_purchase', 'usage_limit', 'starts_at', 'ends_at', 'status']
            ));

            return $promotion->fresh();
        });
    }

    private function syncProducts(Promotion $promotion, array $productIds): void
    {
        PromotionProduct::query()
            ->where('promotion_id', $promotion->id)
            ->delete();

        foreach (array_values(array_filter($productIds)) as $productId) {
            PromotionProduct::create([
                'tenant_id' => $promotion->tenant_id,
                'promotion_id' => $promotion->id,
                'product_id' => $productId,
            ]);
        }
    }

    private function syncRules(Promotion $promotion, array $rules): void
    {
        PromotionRule::query()
            ->where('promotion_id', $promotion->id)
            ->delete();

        foreach (array_values($rules) as $rule) {
            PromotionRule::create([
                'tenant_id' => $promotion->tenant_id,
                'promotion_id' => $promotion->id,
                'rule_type' => $rule['rule_type'],
                'operator' => $rule['operator'] ?? '=',
                'value' => $rule['value'] ?? [],
            ]);
        }
    }
}
