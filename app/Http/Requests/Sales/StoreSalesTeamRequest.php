<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:100'],
            'product_category_id' => ['nullable', 'uuid', 'exists:product_categories,id'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['uuid', 'exists:users,id'],
        ];
    }
}
