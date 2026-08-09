<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

class SendNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['uuid', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'max:50'],
            'channel' => ['nullable', 'string', 'in:dashboard,email,whatsapp,webhook'],
            'data' => ['nullable', 'array'],
        ];
    }
}
