<?php

namespace App\Http\Requests\Promotion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'discount_type' => ['sometimes', 'required', Rule::in(['percentage', 'fixed_amount', 'cashback', 'free_item', 'bundle', 'voucher', 'installment', 'custom'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'minimum_purchase' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['sometimes', 'required', 'date', 'after:starts_at'],
            'status' => ['sometimes', 'required', Rule::in(['draft', 'active', 'expired', 'disabled'])],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['uuid', 'exists:products,id'],
            'rules' => ['nullable', 'array'],
            'rules.*.rule_type' => ['required', Rule::in(['product', 'category', 'minimum_amount', 'date_range', 'customer_segment', 'location', 'quantity', 'campaign'])],
            'rules.*.operator' => ['nullable', Rule::in(['=', '>', '<', '>=', '<=', 'in'])],
            'rules.*.value' => ['required', 'array'],
        ];
    }
}
