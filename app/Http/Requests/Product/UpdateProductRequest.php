<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/'],
            'category_id' => ['nullable', 'uuid', Rule::exists('product_categories', 'id')->where('tenant_id', $this->tenantId())],
            'description' => ['nullable', 'string'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'stock_status' => ['nullable', Rule::in(['available', 'low_stock', 'out_of_stock', 'preorder', 'hidden'])],
            'featured' => ['nullable', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'og_image_url' => ['nullable', 'url'],
            'variants' => ['nullable', 'array'],
            'variants.*.id' => ['nullable', 'uuid'],
            'variants.*.name' => ['required', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:100'],
            'variants.*.price' => ['required', 'numeric', 'min:0'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0'],
            'variants.*.status' => ['nullable', Rule::in(['active', 'inactive'])],
            'variants.*.metadata' => ['nullable', 'array'],
            'attributes' => ['nullable', 'array'],
            'attributes.*.key' => ['required', 'string', 'max:100'],
            'attributes.*.value' => ['nullable', 'string'],
            'attributes.*.type' => ['nullable', Rule::in(['text', 'number', 'boolean', 'date'])],
        ];
    }

    public function tenantId(): ?string
    {
        return $this->attributes->get('tenant')?->id;
    }
}
