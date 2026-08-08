<?php

namespace App\Http\Requests\Lead;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenant = $this->attributes->get('tenant');

        return [
            'status' => [
                'required',
                'string',
                Rule::exists('pipeline_stages', 'key')->where(
                    'tenant_id',
                    $tenant?->id ?? '00000000-0000-0000-0000-000000000000'
                ),
            ],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
