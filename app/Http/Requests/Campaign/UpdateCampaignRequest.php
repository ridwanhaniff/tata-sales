<?php

namespace App\Http\Requests\Campaign;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'paused', 'completed'])],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'sources' => ['nullable', 'array'],
            'sources.*.utm_source' => ['nullable', 'string', 'max:100'],
            'sources.*.utm_medium' => ['nullable', 'string', 'max:100'],
            'sources.*.utm_content' => ['nullable', 'string', 'max:100'],
            'sources.*.utm_term' => ['nullable', 'string', 'max:100'],
            'sources.*.referrer' => ['nullable', 'string', 'max:500'],
            'sources.*.landing_page_id' => ['nullable', 'uuid'],
        ];
    }
}
