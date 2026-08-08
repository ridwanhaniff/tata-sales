<?php

namespace App\Http\Requests\LandingPage;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePageSectionRequest extends FormRequest
{
    public const BLOCK_TYPES = ['hero', 'product', 'product_grid', 'banner', 'faq', 'footer', 'promo', 'countdown', 'calculator', 'lead_form', 'testimonials', 'article', 'cta', 'whatsapp', 'chat'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'block_type' => ['required', Rule::in(self::BLOCK_TYPES)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'config' => ['required', 'array'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }
}
