<?php

namespace App\Http\Requests\Pipeline;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePipelineStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->attributes->get('tenant')?->id
            ?? '00000000-0000-0000-0000-000000000000';

        return [
            'key' => [
                'required',
                'string',
                'max:30',
                'regex:/^[A-Z][A-Z0-9_]*$/',
                Rule::unique('pipeline_stages', 'key')->where('tenant_id', $tenantId),
            ],
            'label' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_won' => ['nullable', 'boolean'],
            'is_lost' => ['nullable', 'boolean'],
        ];
    }
}
