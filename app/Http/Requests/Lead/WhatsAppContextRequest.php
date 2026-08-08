<?php

namespace App\Http\Requests\Lead;

use Illuminate\Foundation\Http\FormRequest;

class WhatsAppContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['nullable', 'string', 'max:255'],
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'calculator_session_id' => ['nullable', 'uuid', 'exists:calculator_sessions,id'],
            'source' => ['sometimes', 'string', 'in:landing,calculator,product'],
        ];
    }
}
