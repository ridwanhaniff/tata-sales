<?php

namespace App\Http\Requests\LandingPage;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePageSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'block_type' => ['sometimes', Rule::in(StorePageSectionRequest::BLOCK_TYPES)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'config' => ['sometimes', 'array'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }
}
