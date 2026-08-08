<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'promotion_id' => $this->promotion_id,
            'code' => $this->code,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value !== null ? (float) $this->discount_value : null,
            'minimum_purchase' => $this->minimum_purchase !== null ? (float) $this->minimum_purchase : null,
            'usage_limit' => $this->usage_limit,
            'per_customer_limit' => $this->per_customer_limit,
            'usage_count' => $this->usage_count,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'promotion' => $this->whenLoaded('promotion', fn () => [
                'id' => $this->promotion->id,
                'name' => $this->promotion->name,
            ]),
        ];
    }
}
