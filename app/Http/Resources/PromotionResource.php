<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'discount_type' => $this->discount_type,
            'discount_value' => (float) $this->discount_value,
            'minimum_purchase' => $this->minimum_purchase !== null ? (float) $this->minimum_purchase : null,
            'usage_limit' => $this->usage_limit,
            'usage_count' => $this->usage_count,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'status' => $this->status,
            'products' => $this->whenLoaded('products', fn () => $this->products->map(fn ($product) => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
            ])),
            'rules' => $this->whenLoaded('rules', fn () => $this->rules->map(fn ($rule) => [
                'id' => $rule->id,
                'rule_type' => $rule->rule_type,
                'operator' => $rule->operator,
                'value' => $rule->value,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
