<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSalesTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'sales_team_id' => ['nullable', 'uuid', 'exists:sales_teams,id'],
            'target_leads' => ['nullable', 'integer', 'min:0'],
            'target_revenue' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
