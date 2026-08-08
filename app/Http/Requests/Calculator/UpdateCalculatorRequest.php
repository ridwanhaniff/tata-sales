<?php

namespace App\Http\Requests\Calculator;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCalculatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::in(['credit', 'kpr', 'wedding_package', 'renovation_estimate', 'custom'])],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
            'inputs' => ['sometimes', 'required', 'array', 'min:1'],
            'inputs.*.key' => ['required', 'string', 'max:100', 'regex:/^[a-z_][a-z0-9_]*$/', 'distinct'],
            'inputs.*.label' => ['required', 'string', 'max:255'],
            'inputs.*.data_type' => ['required', Rule::in(['number', 'select', 'boolean'])],
            'inputs.*.min_value' => ['nullable', 'numeric'],
            'inputs.*.max_value' => ['nullable', 'numeric'],
            'inputs.*.options' => ['nullable', 'array'],
            'inputs.*.options.*.value' => ['required'],
            'inputs.*.options.*.label' => ['required', 'string'],
            'inputs.*.is_required' => ['nullable', 'boolean'],
            'inputs.*.sort_order' => ['nullable', 'integer'],
            'rules' => ['sometimes', 'required', 'array', 'min:1'],
            'rules.*.formula' => ['required', 'string', 'max:500'],
            'rules.*.rounding_policy' => ['nullable', Rule::in(['round', 'floor', 'ceil'])],
            'rules.*.sort_order' => ['nullable', 'integer'],
            'outputs' => ['sometimes', 'required', 'array', 'min:1'],
            'outputs.*.key' => ['required', 'string', 'max:100', 'regex:/^[a-z_][a-z0-9_]*$/', 'distinct'],
            'outputs.*.label' => ['required', 'string', 'max:255'],
            'outputs.*.format' => ['nullable', Rule::in(['currency', 'number', 'text'])],
            'outputs.*.sort_order' => ['nullable', 'integer'],
        ];
    }
}
