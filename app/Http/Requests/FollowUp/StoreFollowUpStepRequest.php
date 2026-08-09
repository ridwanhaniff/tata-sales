<?php

namespace App\Http\Requests\FollowUp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFollowUpStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'trigger_event' => ['required', 'string', 'max:50'],
            'delay_minutes' => ['required', 'integer', 'min:0'],
            'message' => ['nullable', 'string', 'max:2000'],
            'condition' => ['nullable', 'array'],
            'action' => ['nullable', 'string', 'max:30'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }
}
