<?php

namespace App\Http\Requests\Promotion;

use Illuminate\Foundation\Http\FormRequest;

class GenerateVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'count' => ['required', 'integer', 'min:1', 'max:200'],
            'prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/'],
        ];
    }
}
