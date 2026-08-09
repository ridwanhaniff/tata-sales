<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_phone' => ['required', 'string', 'regex:/^[+\d][\d\s()-]{5,18}$/'],
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'string', 'max:36'],
        ];
    }
}
