<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesTeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'region' => $this->region,
            'product_category_id' => $this->product_category_id,
            'product_category' => $this->whenLoaded('productCategory', fn () => [
                'id' => $this->productCategory->id,
                'name' => $this->productCategory->name,
            ]),
            'member_ids' => $this->whenLoaded('members', fn () => $this->members->pluck('id')),
            'member_count' => $this->whenLoaded('members', fn () => $this->members->count()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
