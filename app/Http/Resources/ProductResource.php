<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attributes = [];
        foreach ($this->attributes as $attribute) {
            $value = match ($attribute->attribute_type) {
                'number' => is_numeric($attribute->attribute_value) ? (float) $attribute->attribute_value : $attribute->attribute_value,
                'boolean' => filter_var($attribute->attribute_value, FILTER_VALIDATE_BOOLEAN),
                default => $attribute->attribute_value,
            };
            $attributes[$attribute->attribute_key] = $value;
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
                'slug' => $this->category?->slug,
            ]),
            'short_description' => $this->short_description,
            'description' => $this->when($request->routeIs('*.show'), $this->description),
            'base_price' => (float) $this->base_price,
            'featured' => (bool) $this->featured,
            'stock_status' => $this->stock_status,
            'status' => $this->when($request->user() !== null, $this->status),
            'seo_title' => $this->when($request->user() !== null, $this->seo_title),
            'seo_description' => $this->when($request->user() !== null, $this->seo_description),
            'og_image_url' => $this->when($request->user() !== null, $this->og_image_url),
            'published_at' => $this->when($request->user() !== null, $this->published_at?->toIso8601String()),
            'images' => $this->whenLoaded('images', fn () => $this->images->sortBy('sort_order')->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url,
                'alt_text' => $image->alt_text,
                'sort_order' => $image->sort_order,
            ])->values()),
            'attributes' => $attributes,
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'price' => (float) $variant->price,
                'stock' => $variant->stock,
                'status' => $variant->status,
                'metadata' => $variant->metadata,
            ])),
        ];
    }
}
