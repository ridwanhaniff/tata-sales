<?php

namespace App\Http\Requests\Lead;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenant = $this->attributes->get('tenant');

        return [
            'user_id' => [
                'required',
                'uuid',
                Rule::exists('users', 'id')->where('tenant_id', $tenant?->id ?? '00000000-0000-0000-0000-000000000000'),
            ],
        ];
    }
}
