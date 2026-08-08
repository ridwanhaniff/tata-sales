<?php

namespace App\Http\Requests\Calculator;

use Illuminate\Foundation\Http\FormRequest;

class CalculateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inputs' => ['required', 'array'],
            'product_id' => ['nullable', 'uuid'],
            'lead_id' => ['nullable', 'uuid'],
        ];
    }
}
